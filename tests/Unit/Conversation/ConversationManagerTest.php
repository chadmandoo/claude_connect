<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation;

use App\Conversation\ConversationManager;
use App\Conversation\ConversationState;
use App\Storage\PostgresStore;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

class ConversationManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private ConversationManager $manager;
    private PostgresStore|Mockery\MockInterface $store;
    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->store = Mockery::mock(PostgresStore::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->manager = new ConversationManager();
        $this->setProperty($this->manager, 'store', $this->store);
        $this->setProperty($this->manager, 'logger', $this->logger);
    }

    public function testCreateConversationReturnsUuid(): void
    {
        $this->store->shouldReceive('createConversation')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with(Mockery::type('string'));

        $result = $this->manager->createConversation('user-1');

        $this->assertIsString($result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $result
        );
    }

    public function testGetConversationReturnsArray(): void
    {
        $expected = ['id' => 'conv-1', 'user_id' => 'user-1', 'state' => 'active'];

        $this->store->shouldReceive('getConversation')
            ->once()
            ->with('conv-1')
            ->andReturn($expected);

        $result = $this->manager->getConversation('conv-1');

        $this->assertSame($expected, $result);
    }

    public function testGetConversationReturnsNullWhenNotFound(): void
    {
        $this->store->shouldReceive('getConversation')
            ->once()
            ->with('conv-missing')
            ->andReturn(null);

        $result = $this->manager->getConversation('conv-missing');

        $this->assertNull($result);
    }

    public function testAddTurn(): void
    {
        $this->store->shouldReceive('addConversationTurn')
            ->once()
            ->with('conv-1', Mockery::type('array'));

        $this->store->shouldReceive('getConversation')
            ->once()
            ->with('conv-1')
            ->andReturn(['id' => 'conv-1', 'turn_count' => 2]);

        $this->store->shouldReceive('updateConversation')
            ->once()
            ->with('conv-1', Mockery::on(function (array $data) {
                return isset($data['updated_at']) && $data['turn_count'] === 3;
            }));

        $this->manager->addTurn('conv-1', 'user', 'Hello there');
    }

    public function testCompleteConversation(): void
    {
        $this->store->shouldReceive('updateConversation')
            ->once()
            ->with('conv-1', Mockery::on(function (array $data) {
                return $data['state'] === ConversationState::COMPLETED->value
                    && isset($data['updated_at']);
            }));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Conversation: completed conv-1');

        $this->manager->completeConversation('conv-1');
    }

    public function testListConversations(): void
    {
        $expected = [
            ['id' => 'conv-1', 'state' => 'active'],
            ['id' => 'conv-2', 'state' => 'completed'],
        ];

        $this->store->shouldReceive('listConversations')
            ->once()
            ->with(null, 30)
            ->andReturn($expected);

        $result = $this->manager->listConversations();

        $this->assertCount(2, $result);
        $this->assertSame($expected, $result);
    }
}
