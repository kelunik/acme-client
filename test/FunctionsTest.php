<?php

namespace Kelunik\AcmeClient;

class FunctionsTest extends \PHPUnit_Framework_TestCase {
    public function testResolveServer() {
        $this->assertSame("https://acme-v01.api.letsencrypt.org/directory", resolveServer("letsencrypt"));
        $this->assertSame("https://acme-v01.api.letsencrypt.org/directory", resolveServer("letsencrypt:production"));
        $this->assertSame("https://acme-staging.api.letsencrypt.org/directory", resolveServer("letsencrypt:staging"));
        $this->assertSame("https://acme-v01.api.letsencrypt.org/directory", resolveServer("acme-v01.api.letsencrypt.org/directory"));
        $this->assertSame("https://acme-v01.api.letsencrypt.org/directory", resolveServer("https://acme-v01.api.letsencrypt.org/directory"));
    }

    public function testSuggestCommand() {
        $this->assertSame("acme", suggestCommand("acme!", ["acme"]));
        $this->assertSame("", suggestCommand("issue", ["acme"]));
    }
}