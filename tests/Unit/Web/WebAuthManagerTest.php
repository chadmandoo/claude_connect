<?php

declare(strict_types=1);

namespace Tests\Unit\Web;

use App\Web\WebAuthManager;
use App\Storage\RedisStore;
use Hyperf\Contract\ConfigInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class WebAuthManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private WebAuthManager $auth;
    private RedisStore|Mockery\MockInterface $redis;
    private ConfigInterface|Mockery\MockInterface $config;

    protected function setUp(): void
    {
        $this->redis = Mockery::mock(RedisStore::class);
        $this->config = Mockery::mock(ConfigInterface::class);

        $this->auth = new WebAuthManager();
        $this->setProperty($this->auth, 'redis', $this->redis);
        $this->setProperty($this->auth, 'config', $this->config);
    }

    public function testAuthenticateWithCorrectPassword(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.web.auth_password', '')
            ->andReturn('secret');

        $this->redis->shouldReceive('setWebToken')
            ->once()
            ->with(Mockery::type('string'), 86400);

        $token = $this->auth->authenticate('secret');

        $this->assertNotNull($token);
        $this->assertIsString($token);
        $this->assertSame(32, strlen($token)); // 16 bytes = 32 hex chars
    }

    public function testAuthenticateWithWrongPassword(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.web.auth_password', '')
            ->andReturn('secret');

        $token = $this->auth->authenticate('wrong');

        $this->assertNull($token);
    }

    public function testAuthenticateWithEmptyPasswordConfig(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.web.auth_password', '')
            ->andReturn('');

        $token = $this->auth->authenticate('');

        $this->assertNull($token);
    }

    public function testValidateTokenReturnsTrue(): void
    {
        $this->redis->shouldReceive('hasWebToken')
            ->once()
            ->with('valid-token')
            ->andReturn(true);

        $this->assertTrue($this->auth->validateToken('valid-token'));
    }

    public function testValidateTokenReturnsFalseForInvalid(): void
    {
        $this->redis->shouldReceive('hasWebToken')
            ->once()
            ->with('invalid-token')
            ->andReturn(false);

        $this->assertFalse($this->auth->validateToken('invalid-token'));
    }

    public function testValidateTokenReturnsFalseForEmptyToken(): void
    {
        $this->assertFalse($this->auth->validateToken(''));
    }

    public function testGetUserId(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.web.user_id', 'web_user')
            ->andReturn('test_user');

        $this->assertSame('test_user', $this->auth->getUserId());
    }

    public function testGetUserIdReturnsDefault(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.web.user_id', 'web_user')
            ->andReturn('web_user');

        $this->assertSame('web_user', $this->auth->getUserId());
    }
}
