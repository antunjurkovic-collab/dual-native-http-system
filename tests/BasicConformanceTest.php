<?php

namespace DualNative\HTTP\Tests;

use PHPUnit\Framework\TestCase;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\InMemoryStorage;
use DualNative\HTTP\DualNativeSystem;
use DualNative\HTTP\HTTP\ValidatorGuard;

class BasicConformanceTest extends TestCase
{
    private $dualNativeSystem;
    private $validatorGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dualNativeSystem = new DualNativeSystem();
        $this->validatorGuard = new ValidatorGuard($this->dualNativeSystem->getCIDManager());
    }

    public function testETagEqualsCID()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z',
            'mr_schema' => 'tct-1'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);
        
        // Generate headers using the validator guard
        $headers = $this->validatorGuard->generateStandardHeaders($content);
        
        // Extract ETag from headers and compare to CID
        $etag = $headers['ETag'] ?? null;
        $this->assertNotNull($etag, "ETag header should be present");
        
        // Remove quotes from ETag
        $etagValue = trim($etag, '"');
        
        $this->assertEquals($cid, $etagValue, "ETag should equal CID");
    }

    public function testValidatorGuardMultipleETagProcessing()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);

        // Test with multiple ETags (should match any of them)
        $headers = [
            'if-none-match' => '"wrong1", "wrong2", "' . $cid . '", "wrong3"'
        ];

        $result = $this->validatorGuard->validateIfNoneMatch($headers, $content);
        $this->assertTrue($result, "Should match when one of multiple ETags matches");
    }

    public function testValidatorGuardWeakETagProcessing()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);

        // Test with weak ETag
        $headers = [
            'if-none-match' => 'W/"' . $cid . '"'
        ];

        $result = $this->validatorGuard->validateIfNoneMatch($headers, $content);
        $this->assertTrue($result, "Should match when weak ETag matches");
    }

    public function testValidatorGuardIfMatchPositive()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);

        // Test with correct If-Match
        $headers = [
            'if-match' => '"' . $cid . '"'
        ];

        $result = $this->validatorGuard->validateIfMatch($headers, $content);
        $this->assertTrue($result['valid'], "If-Match validation should pass when CID matches");
    }

    public function testValidatorGuardIfMatchNegative()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $headers = [
            'if-match' => '"non_matching_cid_here"'
        ];

        $result = $this->validatorGuard->validateIfMatch($headers, $content);
        $this->assertFalse($result['valid'], "If-Match validation should fail when CID doesn't match");
        $this->assertArrayHasKey('currentCid', $result, "Should return current CID on failure");
    }

    public function testStandardHeadersIncludeRequiredFields()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $headers = $this->validatorGuard->generateStandardHeaders($content);

        $this->assertArrayHasKey('ETag', $headers, "Should include ETag header");
        $this->assertArrayHasKey('Last-Modified', $headers, "Should include Last-Modified header");
        $this->assertArrayHasKey('Cache-Control', $headers, "Should include Cache-Control header");
        $this->assertArrayHasKey('Content-Digest', $headers, "Should include Content-Digest header");
        
        // Check RFC 9530 format for Content-Digest
        $digest = $headers['Content-Digest'];
        $this->assertStringStartsWith('sha-256=:', $digest, "Content-Digest should start with sha-256=:");
        $this->assertStringEndsWith(':', $digest, "Content-Digest should end with :");
    }
}