<?php

declare(strict_types=1);

use App\Database\PostgresConnector;
use App\Database\PostgresConnection;
use App\Storage\PostgresStore;
use App\Storage\RedisStore;
use App\Storage\SwooleTableCache;
use Hyperf\Database\Connection;
use App\StateMachine\TaskManager;
use App\Claude\ProcessManager;
use App\Claude\OutputParser;
use App\Claude\SessionManager;
use App\Memory\MemoryManager;
use App\Skills\SkillRegistry;
use App\Skills\McpConfigGenerator;
use App\Skills\BuiltinSkills;
use App\Prompts\PromptLoader;
use App\Project\ProjectManager;
use App\Project\ProjectOrchestrator;
use App\Cleanup\CleanupAgent;
use App\Embedding\VoyageClient;
use App\Embedding\VectorStore;
use App\Embedding\EmbeddingService;
use App\Nightly\NightlyConsolidationAgent;
use App\Pipeline\PostTaskPipeline;
use App\Pipeline\Stages\ExtractMemoryStage;
use App\Pipeline\Stages\ExtractConversationStage;
use App\Pipeline\Stages\ProjectDetectionStage;
use App\Workflow\TemplateResolver;
use App\Workflow\ItemAgent;
use App\Memory\MemoryAnalytics;
use App\Pipeline\Stages\EmbedConversationStage;
use App\Pipeline\Stages\EmbedTaskResultStage;
use App\Conversation\ConversationManager;
use App\Agent\PromptComposer;
use App\Agent\Router;
use App\Web\WebAuthManager;
use App\Web\ChatManager;
use App\Web\WebSocketHandler;
use App\Web\TaskNotifier;
use App\Epic\EpicManager;
use App\Item\ItemManager;
use App\Note\NoteManager;
use App\Todo\TodoManager;
use App\Chat\AnthropicClient;
use App\Chat\ChatToolHandler;
use App\Chat\ChatConversationStore;
use App\Chat\ChatSystemPromptBuilder;
use App\Chat\ToolDefinitions;
use App\Agent\AgentSupervisor;
use Psr\Log\LoggerInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Redis;

return [
    // Register Postgres connector + connection resolver for Hyperf's database layer
    'db.connector.pgsql' => PostgresConnector::class,
    PostgresStore::class => function (\Psr\Container\ContainerInterface $container) {
        // Register Postgres connection type before first use
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new PostgresConnection($connection, $database, $prefix, $config);
        });
        return new PostgresStore();
    },
    RedisStore::class => RedisStore::class,
    SwooleTableCache::class => SwooleTableCache::class,
    TaskManager::class => TaskManager::class,
    ProcessManager::class => ProcessManager::class,
    OutputParser::class => OutputParser::class,
    SessionManager::class => SessionManager::class,
    MemoryManager::class => MemoryManager::class,
    SkillRegistry::class => SkillRegistry::class,
    McpConfigGenerator::class => McpConfigGenerator::class,
    BuiltinSkills::class => BuiltinSkills::class,
    PromptLoader::class => PromptLoader::class,
    ProjectManager::class => ProjectManager::class,
    ProjectOrchestrator::class => ProjectOrchestrator::class,
    CleanupAgent::class => CleanupAgent::class,
    VoyageClient::class => function (\Psr\Container\ContainerInterface $container) {
        $config = $container->get(ConfigInterface::class);
        return new VoyageClient(
            apiKey: (string) $config->get('mcp.embedding.api_key', ''),
            model: (string) $config->get('mcp.embedding.model', 'voyage-3.5-lite'),
            dimensions: (int) $config->get('mcp.embedding.dimensions', 512),
            batchSize: (int) $config->get('mcp.embedding.batch_size', 64),
            logger: $container->get(LoggerInterface::class),
        );
    },
    VectorStore::class => function (\Psr\Container\ContainerInterface $container) {
        $config = $container->get(ConfigInterface::class);
        return new VectorStore(
            redis: $container->get(Redis::class),
            dimensions: (int) $config->get('mcp.embedding.dimensions', 512),
            logger: $container->get(LoggerInterface::class),
        );
    },
    EmbeddingService::class => function (\Psr\Container\ContainerInterface $container) {
        return new EmbeddingService(
            voyageClient: $container->get(VoyageClient::class),
            vectorStore: $container->get(VectorStore::class),
            logger: $container->get(LoggerInterface::class),
        );
    },
    NightlyConsolidationAgent::class => NightlyConsolidationAgent::class,
    TemplateResolver::class => TemplateResolver::class,
    ConversationManager::class => ConversationManager::class,
    PromptComposer::class => PromptComposer::class,
    Router::class => Router::class,
    WebAuthManager::class => WebAuthManager::class,
    ChatManager::class => ChatManager::class,
    WebSocketHandler::class => WebSocketHandler::class,
    EpicManager::class => EpicManager::class,
    ItemManager::class => ItemManager::class,
    NoteManager::class => NoteManager::class,
    TodoManager::class => TodoManager::class,
    ItemAgent::class => ItemAgent::class,
    MemoryAnalytics::class => MemoryAnalytics::class,
    AnthropicClient::class => function (\Psr\Container\ContainerInterface $container) {
        $config = $container->get(ConfigInterface::class);
        return new AnthropicClient(
            apiKey: (string) $config->get('mcp.chat.api_key', ''),
            model: (string) $config->get('mcp.chat.model', 'claude-sonnet-4-20250514'),
            maxTokens: (int) $config->get('mcp.chat.max_tokens', 4096),
            temperature: (float) $config->get('mcp.chat.temperature', 0.7),
            maxToolRounds: (int) $config->get('mcp.chat.max_tool_rounds', 10),
            logger: $container->get(LoggerInterface::class),
        );
    },
    ChatToolHandler::class => ChatToolHandler::class,
    ChatConversationStore::class => function (\Psr\Container\ContainerInterface $container) {
        $config = $container->get(ConfigInterface::class);
        $store = new ChatConversationStore(
            compactionThreshold: (int) $config->get('mcp.chat.compaction_threshold', 30),
        );
        return $store;
    },
    ChatSystemPromptBuilder::class => ChatSystemPromptBuilder::class,
    ToolDefinitions::class => ToolDefinitions::class,
    TaskNotifier::class => TaskNotifier::class,
    AgentSupervisor::class => AgentSupervisor::class,
    PostTaskPipeline::class => function (\Psr\Container\ContainerInterface $container) {
        $pipeline = new PostTaskPipeline(
            $container->get(LoggerInterface::class),
        );

        $pipeline->registerStage(new ExtractMemoryStage(
            $container->get(TaskManager::class),
            $container->get(ProcessManager::class),
            $container->get(MemoryManager::class),
            $container->get(LoggerInterface::class),
        ));
        $pipeline->registerStage(new ExtractConversationStage(
            $container->get(TaskManager::class),
            $container->get(ProcessManager::class),
            $container->get(MemoryManager::class),
            $container->get(ConversationManager::class),
            $container->get(ItemManager::class),
            $container->get(PromptLoader::class),
            $container->get(LoggerInterface::class),
        ));
        $pipeline->registerStage(new ProjectDetectionStage(
            $container->get(TaskManager::class),
            $container->get(ProcessManager::class),
            $container->get(ProjectManager::class),
            $container->get(ConfigInterface::class),
            $container->get(LoggerInterface::class),
        ));
        $pipeline->registerStage(new EmbedConversationStage(
            $container->get(ConversationManager::class),
            $container->get(EmbeddingService::class),
            $container->get(LoggerInterface::class),
        ));
        $pipeline->registerStage(new EmbedTaskResultStage(
            $container->get(TaskManager::class),
            $container->get(EmbeddingService::class),
            $container->get(LoggerInterface::class),
        ));

        return $pipeline;
    },
];
