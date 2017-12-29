<?php

namespace Kelunik\AcmeClient\Stores;

use Amp\File;
use Amp\Promise;
use function Amp\call;

class ChallengeStore {
    private $docroot;

    public function __construct(string $docroot) {
        $this->docroot = rtrim(str_replace("\\", '/', $docroot), '/');
    }

    public function put(string $token, string $payload, string $user = null): Promise {
        return call(function () use ($token, $payload, $user) {
            $path = $this->docroot . '/.well-known/acme-challenge';
            $userInfo = null;

            if (!yield File\exists($this->docroot)) {
                throw new ChallengeStoreException("Document root doesn't exist: '{$this->docroot}'");
            }

            if (!yield File\isdir($path) && !yield File\mkdir($path, 0644, true) && !yield File\isdir($path)) {
                throw new ChallengeStoreException("Couldn't create key directory: '{$path}'");
            }

            if ($user && !$userInfo = posix_getpwnam($user)) {
                throw new ChallengeStoreException("Unknown user: '{$user}'");
            }

            if ($userInfo !== null) {
                yield File\chown($this->docroot . '/.well-known', $userInfo['uid'], -1);
                yield File\chown($this->docroot . '/.well-known/acme-challenge', $userInfo['uid'], -1);
            }

            yield \Amp\File\put("{$path}/{$token}", $payload);

            if ($userInfo !== null) {
                yield \Amp\File\chown("{$path}/{$token}", $userInfo['uid'], -1);
            }

            yield \Amp\File\chmod("{$path}/{$token}", 0644);
        });
    }

    public function delete(string $token): Promise {
        return call(function () use ($token) {
            $path = $this->docroot . "/.well-known/acme-challenge/{$token}";

            if (yield File\exists($path)) {
                yield \Amp\File\unlink($path);
            }
        });
    }
}