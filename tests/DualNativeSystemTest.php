<?php

namespace DualNative\HTTP\Tests;

use PHPUnit\Framework\TestCase;
use DualNative\HTTP\Events\NullEventDispatcher;
use DualNative\HTTP\Storage\InMemoryStorage;
use DualNative\HTTP\DualNativeSystem;

class DualNativeSystemTest extends TestCase
{
    private $dualNativeSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dualNativeSystem = new DualNativeSystem();
    }

    public function testSystemInitialization()
    {
        $this->assertInstanceOf(DualNativeSystem::class, $this->dualNativeSystem);
    }

    public function testCIDComputation()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);
        
        $this->assertStringStartsWith('sha256-', $cid);
        $this->assertStringMatchesFormat('%s-%x', $cid);
    }

    public function testCIDValidation()
    {
        $content = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid = $this->dualNativeSystem->computeCID($content);
        $isValid = $this->dualNativeSystem->validateCID($content, $cid);

        $this->assertTrue($isValid);
    }

    public function testCIDValidationWithDifferentContent()
    {
        $content1 = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $content2 = [
            'title' => 'Test Article',
            'content' => 'This is different content',
            'modified' => '2025-01-15T10:00:00Z'
        ];

        $cid1 = $this->dualNativeSystem->computeCID($content1);
        $isValid = $this->dualNativeSystem->validateCID($content2, $cid1);

        $this->assertFalse($isValid);
    }

    public function testBidirectionalLinksGeneration()
    {
        $rid = 'test-123';
        $hrUrl = 'https://example.com/article/123';
        $mrUrl = 'https://api.example.com/v2/article/123';

        $links = $this->dualNativeSystem->generateLinks($rid, $hrUrl, $mrUrl);

        $this->assertArrayHasKey('hr', $links);
        $this->assertArrayHasKey('mr', $links);
        $this->assertEquals($hrUrl, $links['hr']['url']);
        $this->assertEquals($mrUrl, $links['mr']['url']);
    }

    public function testSemanticEquivalenceValidation()
    {
        $hrContent = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z',
            'status' => 'published'
        ];

        $mrContent = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z',
            'status' => 'published'
        ];

        $result = $this->dualNativeSystem->validateSemanticEquivalence($hrContent, $mrContent);

        $this->assertTrue($result['isValid']);
        $this->assertEmpty($result['differences']);
    }

    public function testSemanticEquivalenceValidationFailure()
    {
        $hrContent = [
            'title' => 'Test Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z',
            'status' => 'published'
        ];

        $mrContent = [
            'title' => 'Different Article',
            'content' => 'This is test content',
            'modified' => '2025-01-15T10:00:00Z',
            'status' => 'published'
        ];

        $result = $this->dualNativeSystem->validateSemanticEquivalence($hrContent, $mrContent);

        $this->assertFalse($result['isValid']);
        $this->assertNotEmpty($result['differences']);
    }

    public function testSystemHealthCheck()
    {
        $health = $this->dualNativeSystem->healthCheck();

        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('timestamp', $health);
        $this->assertArrayHasKey('version', $health);
        $this->assertArrayHasKey('components', $health);
        $this->assertArrayHasKey('profile', $health);

        $this->assertEquals('healthy', $health['status']);
    }
}