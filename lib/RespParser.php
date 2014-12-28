<?php

namespace Amphp\Redis;

class RespParser {
	const CRLF = "\r\n";

	private $responseCallback;
	private $buffer = "";
	private $arraySize;
	private $arrayStack;
	private $curSize;
	private $curResponse = null;

	public function __construct (callable $responseCallback) {
		$this->responseCallback = $responseCallback;
	}

	public function append ($str) {
		$this->buffer .= $str;

		while ($this->tryParse()) ;
	}

	private function tryParse () {
		if (strlen($this->buffer) === 0) {
			return false;
		}

		$type = $this->buffer[0];
		$pos = strpos($this->buffer, self::CRLF);

		if ($pos === false) {
			return false;
		}

		switch ($type) {
			case Resp::TYPE_SIMPLE_STRING:
				$this->onRespParsed($type, substr($this->buffer, 1, $pos - 1));
				$this->buffer = substr($this->buffer, $pos + 2);
				return true;

			case Resp::TYPE_ERROR:
				$this->onRespParsed($type, new RedisException(substr($this->buffer, 1, $pos - 1)));
				$this->buffer = substr($this->buffer, $pos + 2);
				return true;

			case Resp::TYPE_ARRAY:
			case Resp::TYPE_INTEGER:
				$this->onRespParsed($type, (int) substr($this->buffer, 1, $pos - 1));
				$this->buffer = substr($this->buffer, $pos + 2);
				return true;

			case Resp::TYPE_BULK_STRING:
				$length = (int) substr($this->buffer, 1, $pos);

				if (strlen($this->buffer) < $pos + $length + 4) {
					return false;
				}

				$this->onRespParsed($type, substr($this->buffer, $pos + 2, $length));
				$this->buffer = substr($this->buffer, $pos + $length + 4);
				return true;

			default:
				throw new RedisException (
					sprintf("unknown resp data type: %s", $type)
				);
		}
	}

	private function onRespParsed ($type, $payload) {
		if ($this->curResponse !== null) {
			if ($type === Resp::TYPE_ARRAY) {
				if ($payload >= 0) {
					$this->arraySize[] = $this->curSize;
					$this->curSize = $payload + 1;
					$this->arrayStack[] = &$this->curResponse;
					$this->curResponse[] = [];
					$this->curResponse = &$this->curResponse[sizeof($this->curResponse) - 1];
				} else {
					$this->curResponse[] = null;
				}
			} else {
				$this->curResponse[] = $payload;
			}

			while (--$this->curSize === 0) {
				if (sizeof($this->arrayStack) === 0) {
					call_user_func($this->responseCallback, $this->curResponse);
					$this->curResponse = null;
					break;
				}

				end($this->arrayStack);
				$key = key($this->arrayStack);
				$this->curResponse = &$this->arrayStack[$key];
				unset($this->arrayStack[$key]);
				$this->curSize = array_pop($this->arraySize);
			}
		} else if ($type === Resp::TYPE_ARRAY) {
			if ($payload > 0) {
				$this->curSize = $payload;
				$this->curResponse = $this->arraySize = $this->arrayStack = [];
			} else if ($payload === 0) {
				call_user_func($this->responseCallback, []);
			} else {
				call_user_func($this->responseCallback, null);
			}
		} else {
			call_user_func($this->responseCallback, $payload);
		}
	}
}
