<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\ConnectorType;
use App\Service\AgentModelCatalogService;
use App\ValueObject\AgentModelInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists the models discovered for one connector with optional extended metadata.
 */
#[AsCommand(
    name: 'somanagent:agent:models',
    description: 'Lists the models available for one connector',
)]
final class ListAgentModelsCommand extends Command
{
    /**
     * Wires the catalog service used to render the discovered models for one connector.
     */
    public function __construct(private readonly AgentModelCatalogService $agentModelCatalogService)
    {
        parent::__construct();
    }

    /**
     * Declares the connector selection and display options supported by the command.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('connector', InputArgument::REQUIRED, 'Connector value such as claude_api, codex_api, codex_cli, or opencode_cli')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the connector models cache before listing')
            ->addOption('details', null, InputOption::VALUE_NONE, 'Show extended metadata for each discovered model');
    }

    /**
     * Renders the discovered model catalog for one connector, optionally with extended metadata sections.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connectorValue = (string) $input->getArgument('connector');

        try {
            $connector = ConnectorType::from($connectorValue);
        } catch (\ValueError) {
            $acceptedValues = implode(', ', array_map(static fn (ConnectorType $type): string => $type->value, ConnectorType::cases()));
            $io->error(sprintf('Invalid connector: %s. Accepted values: %s.', $connectorValue, $acceptedValues));
            return Command::INVALID;
        }

        $catalog = $this->agentModelCatalogService->describeConnector(
            connector: $connector,
            refresh: (bool) $input->getOption('refresh'),
        );

        $io->title(sprintf('SoManAgent — Models for %s', $connector->label()));
        $io->writeln(sprintf('Selection strategy: <info>%s</info>', $catalog->selectionStrategy));
        $io->writeln(sprintf('Recommended model: <info>%s</info>', $catalog->recommendedModel ?? 'n/a'));
        $io->writeln(sprintf('Catalog source: <info>%s</info>', $catalog->cached ? 'cache' : 'live discovery'));

        if (!$catalog->supportsModelDiscovery) {
            $io->warning('This connector does not expose runtime model discovery.');
            return Command::SUCCESS;
        }

        if ($catalog->models === []) {
            $io->warning('No models were discovered for this connector.');

            foreach ($catalog->advisories as $advisory) {
                $io->writeln(sprintf('- %s', $advisory->message));
            }

            return Command::SUCCESS;
        }

        $io->table(
            ['Model', 'Provider', 'Family', 'Status', 'Pricing', 'Context', 'Output'],
            array_map(
                fn (AgentModelInfo $model): array => [
                    $model->id,
                    $model->provider ?? 'n/a',
                    $model->family ?? 'n/a',
                    $this->extractModelMetadataString($model, 'status'),
                    $this->extractPricingLabel($model),
                    $model->contextWindow ?? 'n/a',
                    $model->maxOutputTokens ?? 'n/a',
                ],
                $catalog->models,
            ),
        );

        if ((bool) $input->getOption('details')) {
            foreach ($catalog->models as $model) {
                $io->section($model->id);
                $io->definitionList(
                    ['Label' => $model->label ?: $model->id],
                    ['Description' => $model->description ?? 'n/a'],
                    ['Provider' => $model->provider ?? 'n/a'],
                    ['Family' => $model->family ?? 'n/a'],
                    ['Status' => $this->extractModelMetadataString($model, 'status')],
                    ['Pricing' => $this->extractPricingLabel($model)],
                    ['Release date' => $this->extractModelMetadataString($model, 'releaseDate')],
                    ['Capabilities' => $this->formatCapabilities($model)],
                );
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Reads a string metadata value from the model and falls back to typed top-level properties when needed.
     */
    private function extractModelMetadataString(AgentModelInfo $model, string $key): string
    {
        $value = $model->metadata[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return match ($key) {
            'status' => $model->status ?? 'n/a',
            'releaseDate' => $model->releaseDate ?? 'n/a',
            default => 'n/a',
        };
    }

    /**
     * Formats the typed pricing snapshot into a compact human-readable label for CLI output.
     */
    private function extractPricingLabel(AgentModelInfo $model): string
    {
        $pricing = $model->pricing;

        if ($pricing?->isFree === true) {
            return 'free';
        }

        if ($pricing !== null && ($pricing->input !== null || $pricing->output !== null)) {
            return sprintf(
                'input=%s output=%s',
                (string) ($pricing->input ?? '?'),
                (string) ($pricing->output ?? '?'),
            );
        }

        return 'paid/unknown';
    }

    /**
     * Formats the enabled typed capabilities into a compact human-readable label for CLI output.
     */
    private function formatCapabilities(AgentModelInfo $model): string
    {
        $capabilities = $model->capabilities;

        if ($capabilities === null) {
            return 'n/a';
        }

        $flags = [];

        foreach ([
            'reasoning' => $capabilities->reasoning,
            'toolcall' => $capabilities->toolCall,
            'attachment' => $capabilities->attachment,
            'temperature' => $capabilities->temperature,
        ] as $capability => $enabled) {
            if ($enabled === true) {
                $flags[] = $capability;
            }
        }

        return $flags === [] ? 'n/a' : implode(', ', $flags);
    }
}
