<?php
/**
 * Class Decoder
 *
 * @created      17.01.2021
 * @author       ZXing Authors
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2021 Smiley
 * @license      Apache-2.0
 */

namespace chillerlan\QRCode\Decoder;

use InvalidArgumentException, RuntimeException, Throwable;
use chillerlan\QRCode\Common\{BitBuffer, EccLevel, Mode, ReedSolomonDecoder, Version};
use chillerlan\QRCode\Data\{AlphaNum, Byte, ECI, Kanji, Number};
use chillerlan\QRCode\Detector\Detector;
use function count, array_fill, mb_convert_encoding, mb_detect_encoding;

/**
 * The main class which implements QR Code decoding -- as opposed to locating and extracting
 * the QR Code from an image.
 *
 * @author Sean Owen
 */
final class Decoder{

#	private const GB2312_SUBSET = 1;

	/**
	 * Decodes a QR Code represented as a BitMatrix.
	 * A 1 or "true" is taken to mean a black module.
	 *
	 * @param \chillerlan\QRCode\Decoder\LuminanceSourceInterface $source
	 *
	 * @return \chillerlan\QRCode\Decoder\DecoderResult text and bytes encoded within the QR Code
	 * @throws \Throwable if the QR Code cannot be decoded
	 */
	public function decode(LuminanceSourceInterface $source):DecoderResult{
		$bitMatrix = (new Detector($source))->detect();

		try{
			// clone the BitMatrix to avoid errors in case we run into mirroring
			return $this->decodeMatrix(clone $bitMatrix);
		}
		catch(Throwable $e){

			try{
				/*
				 * Prepare for a mirrored reading.
				 *
				 * Since we're here, this means we have successfully detected some kind
				 * of version and format information when mirrored. This is a good sign,
				 * that the QR code may be mirrored, and we should try once more with a
				 * mirrored content.
				 */
				return $this->decodeMatrix($bitMatrix->setMirror(true)->mirror());
			}
			catch(Throwable $f){
				// Throw the exception from the original reading
				throw $e;
			}

		}

	}

	/**
	 * @param \chillerlan\QRCode\Decoder\BitMatrix $bitMatrix
	 *
	 * @return \chillerlan\QRCode\Decoder\DecoderResult
	 */
	private function decodeMatrix(BitMatrix $bitMatrix):DecoderResult{
		// Read raw codewords
		$rawCodewords = $bitMatrix->readCodewords();
		$version      = $bitMatrix->getVersion();
		$formatInfo   = $bitMatrix->getFormatInfo();

		// technically this shouldn't happen as the respective read meathods would throw first
		if($version === null || $formatInfo === null){
			throw new RuntimeException('unable to read version or ecc level');
		}

		$eccLevel = $formatInfo->getErrorCorrectionLevel();

		// Separate into data blocks
		$dataBlocks = $this->getDataBlocks($rawCodewords, $version, $eccLevel);

		$resultBytes  = [];
		$resultOffset = 0;

		// Error-correct and copy data blocks together into a stream of bytes
		foreach($dataBlocks as $dataBlock){
			[$numDataCodewords, $codewordBytes] = $dataBlock;

			$corrected = $this->correctErrors($codewordBytes, $numDataCodewords);

			for($i = 0; $i < $numDataCodewords; $i++){
				$resultBytes[$resultOffset++] = $corrected[$i];
			}
		}

		// Decode the contents of that stream of bytes
		return $this->decodeBitStream($resultBytes, $version, $eccLevel);
	}

	/**
	 * When QR Codes use multiple data blocks, they are actually interleaved.
	 * That is, the first byte of data block 1 to n is written, then the second bytes, and so on. This
	 * method will separate the data into original blocks.
	 *
	 * @param array                              $rawCodewords bytes as read directly from the QR Code
	 * @param \chillerlan\QRCode\Common\Version  $version      version of the QR Code
	 * @param \chillerlan\QRCode\Common\EccLevel $eccLevel     error-correction level of the QR Code
	 *
	 * @return array DataBlocks containing original bytes, "de-interleaved" from representation in the QR Code
	 * @throws \InvalidArgumentException
	 */
	private function getDataBlocks(array $rawCodewords, Version $version, EccLevel $eccLevel):array{

		if(count($rawCodewords) !== $version->getTotalCodewords()){
			throw new InvalidArgumentException('$rawCodewords differ from total codewords for version');
		}

		// Figure out the number and size of data blocks used by this version and
		// error correction level
		[$numEccCodewords, $eccBlocks] = $version->getRSBlocks($eccLevel);

		// Now establish DataBlocks of the appropriate size and number of data codewords
		$result          = [];//new DataBlock[$totalBlocks];
		$numResultBlocks = 0;

		foreach($eccBlocks as $blockData){
			[$numEccBlocks, $eccPerBlock] = $blockData;

			for($i = 0; $i < $numEccBlocks; $i++, $numResultBlocks++){
				$result[$numResultBlocks] = [$eccPerBlock, array_fill(0, $numEccCodewords + $eccPerBlock, 0)];
			}
		}

		// All blocks have the same amount of data, except that the last n
		// (where n may be 0) have 1 more byte. Figure out where these start.
		/** @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset */
		$shorterBlocksTotalCodewords = count($result[0][1]);
		$longerBlocksStartAt         = count($result) - 1;

		while($longerBlocksStartAt >= 0){
			$numCodewords = count($result[$longerBlocksStartAt][1]);

			if($numCodewords == $shorterBlocksTotalCodewords){
				break;
			}

			$longerBlocksStartAt--;
		}

		$longerBlocksStartAt++;

		$shorterBlocksNumDataCodewords = $shorterBlocksTotalCodewords - $numEccCodewords;
		// The last elements of result may be 1 element longer;
		// first fill out as many elements as all of them have
		$rawCodewordsOffset = 0;

		for($i = 0; $i < $shorterBlocksNumDataCodewords; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$result[$j][1][$i] = $rawCodewords[$rawCodewordsOffset++];
			}
		}

		// Fill out the last data block in the longer ones
		for($j = $longerBlocksStartAt; $j < $numResultBlocks; $j++){
			$result[$j][1][$shorterBlocksNumDataCodewords] = $rawCodewords[$rawCodewordsOffset++];
		}

		// Now add in error correction blocks
		/** @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset */
		$max = count($result[0][1]);

		for($i = $shorterBlocksNumDataCodewords; $i < $max; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$iOffset                 = $j < $longerBlocksStartAt ? $i : $i + 1;
				$result[$j][1][$iOffset] = $rawCodewords[$rawCodewordsOffset++];
			}
		}

		return $result;
	}

	/**
	 * Given data and error-correction codewords received, possibly corrupted by errors, attempts to
	 * correct the errors in-place using Reed-Solomon error correction.
	 */
	private function correctErrors(array $codewordBytes, int $numDataCodewords):array{
		// First read into an array of ints
		$codewordsInts = [];

		foreach($codewordBytes as $i => $codewordByte){
			$codewordsInts[$i] = $codewordByte & 0xFF;
		}

		$decoded = (new ReedSolomonDecoder)->decode($codewordsInts, (count($codewordBytes) - $numDataCodewords));

		// Copy back into array of bytes -- only need to worry about the bytes that were data
		// We don't care about errors in the error-correction codewords
		for($i = 0; $i < $numDataCodewords; $i++){
			$codewordBytes[$i] = $decoded[$i];
		}

		return $codewordBytes;
	}

	/**
	 * @throws \RuntimeException
	 */
	private function decodeBitStream(array $bytes, Version $version, EccLevel $ecLevel):DecoderResult{
		$bits           = new BitBuffer($bytes);
		$symbolSequence = -1;
		$parityData     = -1;
		$versionNumber  = $version->getVersionNumber();

		$result      = '';
		$eciCharset  = null;
#		$fc1InEffect = false;

		// While still another segment to read...
		while($bits->available() >= 4){
			$datamode = $bits->read(4); // mode is encoded by 4 bits

			// OK, assume we're done. Really, a TERMINATOR mode should have been recorded here
			if($datamode === Mode::TERMINATOR){
				break;
			}

			if($datamode === Mode::ECI){
				// Count doesn't apply to ECI
				$eciCharset = ECI::parseValue($bits);
			}
			/** @noinspection PhpStatementHasEmptyBodyInspection */
			elseif($datamode === Mode::FNC1_FIRST || $datamode === Mode::FNC1_SECOND){
				// We do little with FNC1 except alter the parsed result a bit according to the spec
#				$fc1InEffect = true;
			}
			elseif($datamode === Mode::STRCTURED_APPEND){
				if($bits->available() < 16){
					throw new RuntimeException('structured append: not enough bits left');
				}
				// sequence number and parity is added later to the result metadata
				// Read next 8 bits (symbol sequence #) and 8 bits (parity data), then continue
				$symbolSequence = $bits->read(8);
				$parityData     = $bits->read(8);
			}
			else{
				// First handle Hanzi mode which does not start with character count
/*				if($datamode === Mode::DATA_HANZI){
					//chinese mode contains a sub set indicator right after mode indicator
					$subset = $bits->read(4);
					$length = $bits->read(Mode::getLengthBitsForVersion($datamode, $versionNumber));
					if($subset === self::GB2312_SUBSET){
						$result .= $this->decodeHanziSegment($bits, $length);
					}
				}*/
#				else{
					// "Normal" QR code modes:
					if($datamode === Mode::NUMBER){
						$result .= Number::decodeSegment($bits, $versionNumber);
					}
					elseif($datamode === Mode::ALPHANUM){
						$str = AlphaNum::decodeSegment($bits, $versionNumber);

						// See section 6.4.8.1, 6.4.8.2
/*						if($fc1InEffect){
							$start = \strlen($str);
							// We need to massage the result a bit if in an FNC1 mode:
							for($i = $start; $i < $start; $i++){
								if($str[$i] === '%'){
									if($i < $start - 1 && $str[$i + 1] === '%'){
										// %% is rendered as %
										$str = \substr_replace($str, '', $i + 1, 1);//deleteCharAt(i + 1);
									}
#									else{
										// In alpha mode, % should be converted to FNC1 separator 0x1D @todo
#										$str = setCharAt($i, \chr(0x1D)); // ???
#									}
								}
							}
						}
*/
						$result .= $str;
					}
					elseif($datamode === Mode::BYTE){
						$str = Byte::decodeSegment($bits, $versionNumber);

						if($eciCharset !== null){
							$encoding = $eciCharset->getName();

							if($encoding === null){
								// The spec isn't clear on this mode; see
								// section 6.4.5: t does not say which encoding to assuming
								// upon decoding. I have seen ISO-8859-1 used as well as
								// Shift_JIS -- without anything like an ECI designator to
								// give a hint.
								$encoding = mb_detect_encoding($str, ['ISO-8859-1', 'SJIS', 'UTF-8']);
							}

							$eciCharset = null;
							$str = mb_convert_encoding($str, $encoding);
						}

						$result .= $str;
					}
					elseif($datamode === Mode::KANJI){
						$result .= Kanji::decodeSegment($bits, $versionNumber);
					}
					else{
						throw new RuntimeException('invalid data mode');
					}
#				}
			}
		}

		return new DecoderResult([
			'rawBytes'                 => $bytes,
			'text'                     => $result,
			'version'                  => $version,
			'eccLevel'                 => $ecLevel,
			'structuredAppendParity'   => $parityData,
			'structuredAppendSequence' => $symbolSequence
		]);
	}

}
