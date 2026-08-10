<?php
declare(strict_types=1);

namespace Ewebovky\StatusBundle\Command;

use Ewebovky\StatusBundle\Service\WebStatusCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ewebovky:status',
    description: 'Vypíše stejné informace, jaké vrací endpoint /status.json',
)]
final class StatusCommand extends Command
{
    public function __construct(
        private readonly WebStatusCollector $collector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Vypíše syrový JSON místo tabulky (pro zpracování v skriptu)',
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Hodnota pole "host" — v CLI ji nelze převzít z requestu',
                'localhost',
            )
            ->setHelp(<<<'HELP'
                Sbírá data stejným kolektorem jako HTTP endpoint, ale bez tokenu
                a bez requestu. Hodí se do deploy skriptů a při ladění.

                  <info>%command.full_name%</info>
                  <info>%command.full_name% --json | jq .phpVersion</info>
                  <info>%command.full_name% --host=muj-web.cz</info>
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->collector->collect((string) $input->getOption('host'));

        if ($input->getOption('json')) {
            $json = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );

            // OUTPUT_RAW: hodnoty mohou obsahovat < a >, které by konzole jinak
            // brala jako značky stylu.
            $output->writeln($json, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = [$key, $this->naText($value)];
        }

        (new SymfonyStyle($input, $output))->table(['Klíč', 'Hodnota'], $rows);

        return Command::SUCCESS;
    }

    /**
     * Table umí jen skaláry, takže pole (phpExtensions) i booly (opcacheEnabled)
     * je potřeba převést na řetězec.
     */
    private function naText(mixed $hodnota): string
    {
        return match (true) {
            $hodnota === null  => '—',
            is_bool($hodnota)  => $hodnota ? 'ano' : 'ne',
            is_array($hodnota) => wordwrap(implode(', ', array_map(strval(...), $hodnota)), 60, "\n", true),
            default            => (string) $hodnota,
        };
    }
}
