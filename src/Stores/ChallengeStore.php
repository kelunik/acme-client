<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\Promise;
use Generator;
use function Amp\File\put;
use function Amp\File\unlink;
use function Amp\resolve;

class ChallengeStore {
    private $docroot;

    public function __construct(string $docroot) {
        $this->docroot = rtrim(str_replace("\\", "/", $docroot), "/");
    }

    public function put(string $token, string $payload, string $user): Promise {
        return resolve($this->doPut($token, $payload, $user));
    }

    private function doPut(string $token, string $payload, string $user): Generator {
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

        yield put("{$path}/{$token}", $payload);

        chown("{$path}/{$token}", $userInfo["uid"]);
        chmod("{$path}/{$token}", 0660);
    }

    public function delete(string $token): Promise {
        return resolve($this->doDelete($token));
    }

    private function doDelete(string $token): Generator {
        $path = $this->docroot . "/.well-known/acme-challenge/{$token}";
        $realpath = realpath($path);

        if ($realpath) {
            yield unlink($realpath);
        }
    }
}