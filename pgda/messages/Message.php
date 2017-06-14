<?php
/**
 * Created by PhpStorm.
 * User: Branislav Malidzan
 * Date: 08.06.2017
 * Time: 16:26
 */
declare(strict_types = 1);

namespace Pgda\Messages;

use Configs\PgdaCodes;
use Pgda\Fields\PField;
use Pgda\Fields\UField;

class Message implements \Iterator
{
    private $position = 0;
    private $errorMessage = [
        'write' => [],
        'read'  => []
    ];
    protected $messageId;
    private $aamsGioco;
    private $aamsGiocoId;
    private $stack = [];
    private $positionEnds = [];
    private $transactionCode;
    private $binaryMessage;
    private $headerMessageEncoded;
    private $bodyMessageEncoded;
    private $headerMessageDecoded;
    private $bodyMessageDecoded;

    public function current()
    {
        return $this->stack [$this->position];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        $this->position++;
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function valid()
    {
        return isset ($this->stack [$this->position]);
    }

    public function send(string $transactionCode, int $aamsGameCode, int $aamsGameType, string $serverPathSuffix)
    {
        $this->setTransactionCode($transactionCode);
        $this->setAamsGameCode($aamsGameCode);
        $this->setAamsGameType($aamsGameType);
        $this->buildMessage();
    }

    /**
     * @return void
     */
    private function buildMessage(): void
    {
        $this->prepare();
        $this->bodyMessageEncoded = $this->writeBody($this);
        $this->headerMessageEncoded = $this->writeHeader();//continue here
    }

    private function writeHeader()
    {
        if (is_null($this->bodyMessageEncoded)) {
            throw new \LogicException("Can not write header packet while bodyMessage is not written. Error in " . __METHOD__ . " on line: " . __LINE__);
        }
        if (empty($this->messageId)) {
            throw new \LogicException ("Can not write header packet while message Id is not defined. Error in " . __METHOD__ . " on line: " . __LINE__);
        }
        if ($this->messageId >= 800) {
            $this->aamsGiocoId = 0;
            $this->aamsGioco = 0;
        } else {
            if (empty ($this->aamsGiocoId)) {
                throw new \LogicException ("Can not write header packet while aamsGiocoId is not defined. Error in " . __METHOD__ . " on line: " . __LINE__);
            }
            if (is_null($this->aamsGioco)) {
                throw new \LogicException ("Can not write header packet while aamsGioco is not defined Error in " . __METHOD__ . " on line: " . __LINE__);
            }
        }
        error_log("AAMS_CONC: " . PgdaCodes::getPgdaAamsCodes('conc') . " AAMS_FSC: " . PgdaCodes::getPgdaAamsCodes('fsc') . " AAMS_GIOCO: " . $this->aamsGioco . " TRANSACTION_ID: " . $this->transactionCode . " MSG_TYPE: " . $this->messageId);
        $messageHeader = new Message();
        $messageHeader->attach(PField::set("Num. vers. Protoc.", PField::byte, 2));
        $messageHeader->attach(PField::set("Cod. Forn. Servizi", PField::int, PgdaCodes::getPgdaAamsCodes('fsc')));
        $messageHeader->attach(PField::set("Cod. Conc. Trasm.", PField::int, PgdaCodes::getPgdaAamsCodes('conc')));
        $messageHeader->attach(PField::set("Cod. Conc. Propo.", PField::int, PgdaCodes::getPgdaAamsCodes('conc')));
        $messageHeader->attach(PField::set("Codice Gioco.", PField::int, $this->aamsGioco));
        $messageHeader->attach(PField::set("Cod. Tipo Gioco.", PField::byte, $this->aamsGiocoId));
        $messageHeader->attach(PField::set("Tipo Mess.", PField::string, $this->messageId, 4));
        $messageHeader->attach(PField::set("Codice transazione", PField::string, $this->getTransactionCode(), 16));
        $messageHeader->attach(PField::set("Lunghezza Body", PField::int, strlen($this->bodyMessageEncoded)));
        return $this->writeBody($messageHeader);
    }

    public function getTransactionCode()
    {
        if (empty($this->transactionCode)) {
            throw new \UnexpectedValueException('Tried to get an EMPTY Transaction Code. Use ::setTransactionCode() First. Error on: ' . __METHOD__ . " in " . __FILE__);
        }
        return $this->transactionCode;
    }

    /**
     * @param Message $message
     * @return string
     */
    private function writeBody(Message $message): string
    {
        $errorMessage = ["\nPacking: "];
        $types = "";
        $values = [];
        $array64Bits = [];
        foreach ($message as $fieldPosition => $field) {
            $errorMessage[] = $field->name . " = " . $field->value;
            if ($field->invoke === PField::bigint) {
                //create real 8 byte string of big int
                $stringBinaryBigInt = $this->write64BitIntegers((string)$field->value);
                //set presence of Big Int in Position $fieldPosition with their binary Calculated Value
                $array64Bits[$fieldPosition] = $stringBinaryBigInt;
                //create 2 fake 4 bytes int
                //fake hWord
                $fakeHighWord = 0x00;
                //fake loWord
                $fakeLowWord = 0x00;
                $values[] = $fakeHighWord;
                $values[] = $fakeLowWord;
            } else {
                $values [] = $field->value;
            }
            $types .= $field->invoke;
        }
        $binaryString = call_user_func_array("pack", array_merge([$types], $values));

        //now replace the fake big int with the real calculated
        foreach ($array64Bits as $fieldPos => $binaryValue) {
            $binaryString = substr_replace($binaryString, $binaryValue, $message->getPositionField($fieldPos), 8);
        }
        $this->errorMessage['write'][] = $errorMessage;
        return $binaryString;
    }

    /**
     * @param int $fieldNum
     * @return int
     */
    public function getPositionField(int $fieldNum): int
    {
        if (!array_key_exists($fieldNum, $this->positionEnds)) {
            throw new \OutOfBoundsException("Can't find a field in Position $fieldNum - Error in: " . __METHOD__ . " on line " . __LINE__);
        }
        return $this->positionEnds[$fieldNum] - ($this->stack[$fieldNum]->typeLength);
    }

    /**
     * @param string|null $bigIntValue
     * @return string
     */
    private function write64BitIntegers(string $bigIntValue = null): string
    {
        if (PHP_INT_SIZE > 4) {
            settype($bigIntValue, 'integer');
            $binaryString = chr($bigIntValue >> 56 & 0xFF) . chr($bigIntValue >> 48 & 0xFF) . chr($bigIntValue >> 40 & 0xFF) . chr($bigIntValue >> 32 & 0xFF) . chr($bigIntValue >> 24 & 0xFF) . chr($bigIntValue >> 16 & 0xFF) . chr($bigIntValue >> 8 & 0xFF) . chr($bigIntValue & 0xFF);

        } else {
            throw new \LengthException('Write error. This Processor can not handle 64bit integers without loss of significant digits. Error in: ' . __METHOD__ . " on line " . __LINE__);
        }
        return $binaryString;

    }

    /**
     * @param string|null $transactionCode
     * @return void
     */
    public function setTransactionCode(string $transactionCode = null): void
    {
        $this->transactionCode = $transactionCode;
    }

    /**
     * @param int $aamsGameCode
     * @return void
     */
    public function setAamsGameCode(int $aamsGameCode): void
    {
        $this->aamsGioco = intval($aamsGameCode);
    }

    /**
     * @param int $aamsGameType
     * @return void
     */
    public function setAamsGameType(int $aamsGameType): void
    {
        $this->aamsGiocoId = $aamsGameType;
    }

    protected function attach($field)
    {
        if (!$field instanceof PField && !$field instanceof UField) {
            throw new \BadMethodCallException('Error, ' . __METHOD__ . " can only accept instances of PField and UField on line: " . __LINE__);
        }
        $this->stack[] = $field;
        $actualPosition = count($this->stack) - 1;
        if (!empty ($this->positionEnds)) {
            $this->positionEnds [$actualPosition] = $this->positionEnds [$actualPosition - 1] + $field->typeLength;
        } else {
            $this->positionEnds [$actualPosition] = $field->typeLength;
        }
    }

}