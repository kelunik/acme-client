<?php

namespace Kelunik\AcmeClient;

use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testResolveServer(): void
    {
        $this->assertSame('https://acme-v02.api.letsencrypt.org/directory', resolveServer('letsencrypt'));
        $this->assertSame('https://acme-v02.api.letsencrypt.org/directory', resolveServer('letsencrypt:production'));
        $this->assertSame(
            'https://acme-staging-v02.api.letsencrypt.org/directory',
            resolveServer('letsencrypt:staging')
        );
        $this->assertSame(
            'https://acme-v01.api.letsencrypt.org/directory',
            resolveServer('acme-v01.api.letsencrypt.org/directory')
        );
        $this->assertSame(
            'https://acme-v01.api.letsencrypt.org/directory',
            resolveServer('https://acme-v01.api.letsencrypt.org/directory')
        );
    }

    public function testSuggestCommand(): void
    {
        $this->assertSame('acme', suggestCommand('acme!', ['acme']));
        $this->assertSame('', suggestCommand('issue', ['acme']));
    }

    public function testIsPhar(): void
    {
        $this->assertFalse(isPhar());
    }

    public function testNormalizePath(): void
    {
        $this->assertSame('/etc/foobar', normalizePath('/etc/foobar'));
        $this->assertSame('/etc/foobar', normalizePath('/etc/foobar/'));
        $this->assertSame('/etc/foobar', normalizePath('/etc/foobar/'));
        $this->assertSame('C:/etc/foobar', normalizePath("C:\\etc\\foobar\\"));
    }
}
