<?php

namespace Kelunik\AcmeClient\Stores;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

class ChallengeStore {
    private $docroot;

    public function __construct($docroot) {
        if (!is_string($docroot)) {
            throw new InvalidArgumentException(sprintf("\$docroot must be of type string, %s given.", gettype($docroot)));
        }

        $this->docroot = rtrim(str_replace("\\", "/", $docroot), "/");
    }

    public function put($token, $payload, $user = null) {
        return \Amp\resolve($this->doPut($token, $payload, $user));
    }

    private function doPut($token, $payload, $user = null) {
        Assert::string($token, "Token must be a string. Got: %s");
        Assert::string($payload, "Payload must be a string. Got: %s");
        Assert::nullOrString($user, "User must be a string or null. Got: %s");

        $path = $this->docroot . "/.well-known/acme-challenge";
        $realpath = realpath($path);

        if (!realpath($this->docroot)) {
            throw new ChallengeStoreException("Document root doesn't exist: '{$this->docroot}'");
        }

        if (!$realpath && !@mkdir($path, 0755, true)) {
            throw new ChallengeStoreException("Couldn't create public directory to serve the challenges: '{$path}'");
        }

        if ($user) {
            if (!$userInfo = posix_getpwnam($user)) {
                throw new ChallengeStoreException("Unknown user: '{$user}'");
            }
        }

        if (isset($userInfo)) {
            yield \Amp\File\chown($this->docroot . "/.well-known", $userInfo["uid"], -1);
            yield \Amp\File\chown($this->docroot . "/.well-known/acme-challenge", $userInfo["uid"], -1);
        }

        yield \Amp\File\put("{$path}/{$token}", $payload);

        if (isset($userInfo)) {
            yield \Amp\File\chown("{$path}/{$token}", $userInfo["uid"], -1);
        }

        yield \Amp\File\chmod("{$path}/{$token}", 0644);
    }

    public function delete($token) {
        return \Amp\resolve($this->doDelete($token));
    }

    private function doDelete($token) {
        Assert::string($token, "Token must be a string. Got: %s");

        $path = $this->docroot . "/.well-known/acme-challenge/{$token}";
        $realpath = realpath($path);

        if ($realpath) {
            yield \Amp\File\unlink($realpath);
        }
    }
}