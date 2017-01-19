<?php

namespace Aerys;

use Amp\{ CallableMaker, Deferred, Emitter, Internal\Producer, Stream, Success };
use AsyncInterop\Loop;

class BodyParser implements Stream {
    use CallableMaker;
    use Producer {
        listen as private watch;
    }

    /** @var \Aerys\Request */
    private $req;

    /** @var \Amp\Message */
    private $body;

    private $boundary = null;

    private $bodyDeferreds = [];
    private $bodies = [];

    /** @var int */
    private $parsing = 0;

    private $size;
    private $totalSize;
    private $usedSize = 0;
    private $sizes = [];
    private $curSizes = [];

    private $maxFieldLen; // prevent buffering of arbitrary long names and fail instead
    private $maxInputVars; // prevent requests from creating arbitrary many fields causing lot of processing time
    private $inputVarCount = 0;
    private $initIncremental;

    /**
     * @param Request $req
     * @param array $options available options are:
     *                       - size (default: 131072)
     *                       - input_vars (default: 200)
     *                       - field_len (default: 16384)
     */
    public function __construct(Request $req, array $options = []) {
        $this->req = $req;
        $type = $req->getHeader("content-type");
        $this->body = $req->getBody($this->totalSize = $this->size = $options["size"] ?? 131072);
        $this->maxFieldLen = $options["field_len"] ?? 16384;
        $this->maxInputVars = $options["input_vars"] ?? 200;

        if ($type !== null && strncmp($type, "application/x-www-form-urlencoded", \strlen("application/x-www-form-urlencoded"))) {
            if (!preg_match('#^\s*multipart/(?:form-data|mixed)(?:\s*;\s*boundary\s*=\s*("?)([^"]*)\1)?$#', $type, $m)) {
                $this->req = null;
                $this->parsing = 1;
                $this->resolve(new ParsedBody([]));
                return;
            }

            $this->boundary = $m[2];
        }

        $this->initIncremental = \Amp\wrap($this->callableFromInstanceMethod('initIncremental'));

        Loop::defer(\Amp\wrap(function() {
            if ($this->parsing === 1) {
                yield from $this->initIncremental();
            }

            try {
                $data = yield $this->body;

                $result = $this->end($data);

                if (!$this->parsing) {
                    $this->parsing = 2;
                    foreach ($result->getNames() as $field) {
                        foreach ($result->getArray($field) as $_) {
                            $this->emit($field);
                        }
                    }
                }

                $this->resolve($result);
            } catch (\Throwable $exception) {
                if ($exception instanceof ClientSizeException) {
                    $exception = new ClientException("", 0, $e);
                }
                $this->error($exception);
            } finally {
                $this->req = null;
            }
        }));
    }

    private function end($data): ParsedBody {
        if (!$this->bodies && $data != "") {
            // if we end up here, we haven't parsed anything at all yet, so do a quick parse
            if ($this->boundary !== null) {
                $fields = $metadata = [];

                // RFC 7578, RFC 2046 Section 5.1.1
                if (strncmp($data, "--$this->boundary\r\n", \strlen($this->boundary) + 4) !== 0) {
                    return new ParsedBody([]);
                }

                $exp = explode("\r\n--$this->boundary\r\n", $data);
                $exp[0] = substr($exp[0], \strlen($this->boundary) + 4);
                $exp[count($exp) - 1] = substr(end($exp), 0, -\strlen($this->boundary) - 8);

                foreach ($exp as $entry) {
                    list($rawheaders, $text) = explode("\r\n\r\n", $entry, 2);
                    $headers = [];

                    foreach (explode("\r\n", $rawheaders) as $header) {
                        $split = explode(":", $header, 2);
                        if (!isset($split[1])) {
                            return new ParsedBody([]);
                        }
                        $headers[strtolower($split[0])] = trim($split[1]);
                    }

                    if (!preg_match('#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#', $headers["content-disposition"] ?? "", $m) || !isset($m[1])) {
                        return new ParsedBody([]);
                    }
                    $name = $m[1];
                    $fields[$name][] = $text;

                    // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                    if (isset($m[2])) {
                        $metadata[$name][count($fields[$name]) - 1] = ["filename" => $m[2], "mime" => $headers["content-type"] ?? "application/octet-stream"];
                    } elseif (isset($headers["content-type"])) {
                        $metadata[$name][count($fields[$name]) - 1]["mime"] = $headers["content-type"];
                    }
                }

                return new ParsedBody($fields, $metadata);

            } else {
                $fields = [];
                foreach (explode("&", $data) as $pair) {
                    $pair = explode("=", $pair, 2);
                    $fields[urldecode($pair[0])][] = urldecode($pair[1] ?? "");
                }
                return new ParsedBody($fields, []);
            }
        } else {
            $fields = $metadata = [];
            $when = static function($e, $data) use (&$fields, &$key) {
                $fields[$key][] = $data;
            };
            $metawhen = static function($e, $data) use (&$metadata, &$key) {
                $metadata[$key][] = $data;
            };

            foreach ($this->bodies as $key => $bodies) {
                foreach ($bodies as $body) {
                    $body->when($when);
                    $body->getMetadata()->when($metawhen);
                }
                $metadata[$key] = array_filter($metadata[$key]);
            }

            return new ParsedBody($fields, array_filter($metadata));
        }
    }

    public function listen(callable $onNext) {
        if ($this->req) {
            $this->watch($onNext);

            if (!$this->parsing) {
                $this->parsing = 1;
                Loop::defer($this->initIncremental);
            }
        } elseif (!$this->parsing) {
            $this->watch($onNext);
        }
    }

    /**
     * @param string $name field name
     * @param int $size <= 0: use last size, if none present, count toward total size, else separate size just respecting value size
     * @return FieldBody
     */
    public function stream(string $name, int $size = 0): FieldBody {
        if ($this->req) {
            if ($size > 0) {
                if (!empty($this->curSizes)) {
                    foreach ($this->curSizes[$name] as $partialSize) {
                        $size -= $partialSize;
                        if (!isset($this->sizes[$name])) {
                            $this->usedSize -= $partialSize - \strlen($name);
                        }
                    }
                }
                $this->sizes[$name] = $size;
                $this->body = $this->req->getBody($this->totalSize += $size);
            }
            if (!$this->parsing) {
                $this->parsing = 1;
                Loop::defer($this->initIncremental);
            }
            if (empty($this->bodies[$name])) {
                $this->bodyDeferreds[$name][] = [$body = new Emitter, $metadata = new Deferred];
                return new FieldBody($body->stream(), $metadata->promise());
            }
        } elseif (empty($this->bodies[$name])) {
            return new FieldBody(new Success, new Success([]));
        }
        
        $key = key($this->bodies[$name]);
        $ret = $this->bodies[$name][$key];
        unset($this->bodies[$name][$key], $this->curSizes[$name][$key]);
        return $ret;
    }

    private function initField($field, $metadata = []) {
        if ($this->inputVarCount++ == $this->maxInputVars || \strlen($field) > $this->maxFieldLen) {
            $this->error();
            return null;
        }

        $this->curSizes[$field] = 0;
        $this->usedSize += \strlen($field);
        if ($this->usedSize > $this->size) {
            $this->error();
            return null;
        }
        
        if (isset($this->bodyDeferreds[$field])) {
            $key = key($this->bodyDeferreds[$field]);
            list($dataEmitter, $metadataDeferred) = $this->bodyDeferreds[$field][$key];
            $metadataDeferred->resolve($metadata);
            unset($this->bodyDeferreds[$field]);
        } else {
            $dataEmitter = new Emitter;
            $this->bodies[$field][] = new FieldBody($dataEmitter->stream(), new Success($metadata));
        }
        
        $this->emit($field);
        
        return $dataEmitter;
    }

    private function updateFieldSize($field, $data) {
        $this->curSizes[$field] += \strlen($data);
        if (isset($this->sizes[$field])) {
            if ($this->curSizes[$field] > $this->sizes[$field]) {
                $this->error();
                return true;
            }
        } else {
            $this->usedSize += \strlen($data);
            if ($this->usedSize > $this->size) {
                $this->error();
                return true;
            }
        }
        return false;
    }

    private function error(\Throwable $e = null) {
        $e = $e ?? new ClientSizeException;
        foreach ($this->bodyDeferreds as list($deferred, $metadata)) {
            $deferred->fail($e);
            $metadata->fail($e);
        }
        $this->bodyDeferreds = [];
        $this->req = null;

        $this->fail($e);
    }

    // this should be inside a defer (not direct Coroutine) to give user a chance to install listen() handlers
    private function initIncremental() {
        if ($this->parsing !== 1) {
            return;
        }
        $this->parsing = 2;

        $buf = "";
        if ($this->boundary) {
            // RFC 7578, RFC 2046 Section 5.1.1
            $sep = "--$this->boundary";
            while (\strlen($buf) < \strlen($sep) + 4) {
                if (!yield $this->body->advance()) {
                    $this->error(new ClientException);
                    return;
                }
                $buf .= $this->body->getCurrent();
            }
            $off = \strlen($sep);
            if (strncmp($buf, $sep, $off)) {
                $this->error(new ClientException);
                return;
            }

            $sep = "\r\n$sep";
            
            while (substr_compare($buf, "--\r\n", $off)) {
                $off += 2;
                
                while (($end = strpos($buf, "\r\n\r\n", $off)) === false) {
                    if (!yield $this->body->advance()) {
                        $this->error(new ClientException);
                        return;
                    }
                    $off = \strlen($buf);
                    $buf .= $this->body->getCurrent();
                }

                $headers = [];

                foreach (explode("\r\n", substr($buf, $off, $end - $off)) as $header) {
                    $split = explode(":", $header, 2);
                    if (!isset($split[1])) {
                        $this->error(new ClientException);
                        return;
                    }
                    $headers[strtolower($split[0])] = trim($split[1]);
                }

                if (!preg_match('#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#', $headers["content-disposition"] ?? "", $m) || !isset($m[1])) {
                    $this->error(new ClientException);
                    return;
                }
                $field = $m[1];

                // Ignore Content-Transfer-Encoding as deprecated and hence we won't support it

                if (isset($m[2])) {
                    $metadata = ["filename" => $m[2], "mime" => $headers["content-type"] ?? "application/octet-stream"];
                } elseif (isset($headers["content-type"])) {
                    $metadata = ["mime" => $headers["content-type"]];
                } else {
                    $metadata = [];
                }
                $dataEmitter = $this->initField($field, $metadata);

                $buf = substr($buf, $end + 4);
                $off = 0;
                
                while (($end = strpos($buf, $sep, $off)) === false) {
                    if (!yield $this->body->advance()) {
                        $e = new ClientException;
                        $dataEmitter->fail($e);
                        $this->error($e);
                        return;
                    }
                    
                    $buf .= $this->body->getCurrent();
                    if (\strlen($buf) > \strlen($sep)) {
                        $off = \strlen($buf) - \strlen($sep);
                        $data = substr($buf, 0, $off);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataEmitter->fail(new ClientSizeException);
                            return;
                        }
                        $dataEmitter->emit($data);
                        $buf = substr($buf, $off);
                    }
                }

                $data = substr($buf, 0, $end);
                if ($this->updateFieldSize($field, $data)) {
                    $dataEmitter->fail(new ClientSizeException);
                    return;
                }
                $dataEmitter->emit($data);
                $dataEmitter->resolve();
                $off = $end + \strlen($sep);

                while (\strlen($buf) < 4) {
                    if (!yield $this->body->advance()) {
                        $this->error(new ClientException);
                        return;
                    }
                    $buf .= $this->body->getCurrent();
                }
            }
        } else {
            $field = null;
            while (yield $this->body->advance()) {
                $new = $this->body->getCurrent();

                if ($new[0] === "&") {
                    if ($field !== null) {
                        if ($noData || $buf != "") {
                            $data = urldecode($buf);
                            if ($this->updateFieldSize($field, $data)) {
                                $dataEmitter->fail(new ClientSizeException);
                                return;
                            }
                            $dataEmitter->emit($data);
                            $buf = "";
                        }
                        $field = null;
                        $dataEmitter->resolve();
                    } elseif ($buf != "") {
                        if (!$dataEmitter = $this->initField(urldecode($buf))) {
                            return;
                        }
                        $dataEmitter->emit("");
                        $dataEmitter->resolve();
                        $buf = "";
                    }
                }

                $buf .= strtok($new, "&");
                if ($field !== null && ($new = strtok("&")) !== false) {
                    $data = urldecode($buf);
                    if ($this->updateFieldSize($field, $data)) {
                        $dataEmitter->fail(new ClientSizeException);
                        return;
                    }
                    $dataEmitter->emit($data);
                    $dataEmitter->resolve();

                    $buf = $new;
                    $field = null;
                }

                while (($next = strtok("&")) !== false) {
                    $pair = explode("=", $buf, 2);
                    $key = urldecode($pair[0]);
                    if (!$dataEmitter = $this->initField($key)) {
                        return;
                    }
                    $data = urldecode($pair[1] ?? "");
                    if ($this->updateFieldSize($key, $data)) {
                        $dataEmitter->fail(new ClientSizeException);
                        return;
                    }
                    $dataEmitter->emit($data);
                    $dataEmitter->resolve();

                    $buf = $next;
                }

                if ($field === null) {
                    if (($new = strstr($buf, "=", true)) !== false) {
                        $field = urldecode($new);
                        if (!$dataEmitter = $this->initField($field)) {
                            return;
                        }
                        $buf = substr($buf, \strlen($new) + 1);
                        $noData = true;
                    } elseif (\strlen($buf) > $this->maxFieldLen) {
                        $this->error();
                        return;
                    }
                }

                if ($field !== null && $buf != "" && (\strlen($buf) > 2 || $buf[0] !== "%")) {
                    if (\strlen($buf) > 1 ? false !== $percent = strrpos($buf, "%", -2) : !($percent = $buf[0] !== "%")) {
                        if ($percent) {
                            if ($this->updateFieldSize($field, $data)) {
                                return;
                            }
                            $dataEmitter->emit(urldecode(substr($buf, 0, $percent)));
                            $buf = substr($buf, $percent);
                        }
                    } else {
                        $data = urldecode($buf);
                        if ($this->updateFieldSize($field, $data)) {
                            $dataEmitter->fail(new ClientSizeException);
                            return;
                        }
                        $dataEmitter->emit($data);
                        $buf = "";
                    }
                    $noData = false;
                }
            }

            if ($field !== null) {
                if ($noData || $buf) {
                    $data = urldecode($buf);
                    if ($this->updateFieldSize($field, $data)) {
                        return;
                    }
                    $dataEmitter->emit($data);
                }
                $dataEmitter->resolve();
                $field = null;
            } elseif ($buf) {
                $field = urldecode($buf);
                if (!$dataEmitter = $this->initField($field)) {
                    return;
                }
                $dataEmitter->emit("");
                $dataEmitter->resolve();
            }
        }

        foreach ($this->bodyDeferreds as $fieldArray) {
            foreach ($fieldArray as list($deferred, $metadata)) {
                $deferred->resolve();
                $metadata->resolve([]);
            }
        }
    }
}