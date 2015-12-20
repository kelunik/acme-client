<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\Promise;
use Generator;
use InvalidArgumentException;

class ChallengeStore {
    private $docroot;

    public function __construct($docroot) {
        if (!is_string($docroot)) {
            throw new InvalidArgumentException(sprintf("\$docroot must be of type string, %s given.", gettype($docroot)));
        }

        $this->docroot = rtrim(str_replace("\\", "/", $docroot), "/");
    }

    public function put($token, $payload, $user) {
        if (!is_string($token)) {
            throw new InvalidArgumentException(sprintf("\$token must be of type string, %s given.", gettype($token)));
        }

        if (!is_string($payload)) {
            throw new InvalidArgumentException(sprintf("\$payload must be of type string, %s given.", gettype($payload)));
        }

        if (!is_string($user)) {
            throw new InvalidArgumentException(sprintf("\$user must be of type string, %s given.", gettype($user)));
        }

        return \Amp\resolve($this->doPut($token, $payload, $user));
    }

    private function doPut($token, $payload, $user) {
        if (!is_string($token)) {
            throw new InvalidArgumentException(sprintf("\$token must be of type string, %s given.", gettype($token)));
        }

        if (!is_string($payload)) {
            throw new InvalidArgumentException(sprintf("\$payload must be of type string, %s given.", gettype($payload)));
        }

        if (!is_string($user)) {
            throw new InvalidArgumentException(sprintf("\$user must be of type string, %s given.", gettype($user)));
        }

        $path = $this->docroot . "/.well-known/acme-challenge";
        $realpath = realpath($path);

        if (!realpath($this->docroot)) {
            throw new ChallengeStoreException("Document root doesn't exist: '{$this->docroot}'");
        }

        if (!$realpath && !@mkdir($path, 0770, true)) {
            throw new ChallengeStoreException("Couldn't create public directory to serve the challenges: '{$path}'");
        }

        if (!$userInfo = posix_getpwnam($user)) {
            throw new ChallengeStoreException("Unknown user: '{$user}'");
        }

        // TODO: Make async, see https://github.com/amphp/file/issues/6
        chown($this->docroot . "/.well-known", $userInfo["uid"]);
        chown($this->docroot . "/.well-known/acme-challenge", $userInfo["uid"]);

        yield \Amp\File\put("{$path}/{$token}", $payload);

        chown("{$path}/{$token}", $userInfo["uid"]);
        chmod("{$path}/{$token}", 0660);
    }

    public function delete($token) {
        if (!is_string($token)) {
            throw new InvalidArgumentException(sprintf("\$token must be of type string, %s given.", gettype($token)));
        }

        return \Amp\resolve($this->doDelete($token));
    }

    private function doDelete($token) {
        if (!is_string($token)) {
            throw new InvalidArgumentException(sprintf("\$token must be of type string, %s given.", gettype($token)));
        }

        $path = $this->docroot . "/.well-known/acme-challenge/{$token}";
        $realpath = realpath($path);

        if ($realpath) {
            yield \Amp\File\unlink($realpath);
        }
    }
}