<?php

namespace Drush\Commands;

use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Endpoints\Code;
use AcquiaCloudApi\Endpoints\DatabaseBackups;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Notifications;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Consolidation\SiteProcess\Util\Shell;
use Drush\Attributes as CLI;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\Log\DrushLoggerManager;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use MassGov\Drush\Attributes\OptionsetDeploy;
use MassGov\Drush\Attributes\ValidateCircleciToken;
use Symfony\Component\Filesystem\Path;

class DeployCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;


  // Set the PHP version to use when deploying to Acquia environments.
  public const PHP_VERSION = '8.2';

  const TARGET_DESC = 'Target environment. Recognized values: dev, cd, test, feature1, feature2, feature3, feature4, feature5, prod';

  const TARGET_LIST = [
    'dev',
    'cd',
    'test',
    'feature1',
    'feature2',
    'feature3',
    'feature4',
    'feature5',
    'prod',
  ];

  const VALIDATE_CIRCLECI_TOKEN = 'validate-circleci-token';

  const CI_BACKSTOP_SNAPSHOT = 'ma:ci:backstop-snapshot';

  const CI_BACKSTOP_COMPARE = 'ma:ci:backstop-compare';

  const MA_BACKUP = 'ma:backup';

  const LATEST_BACKUP_URL = 'ma:latest-backup-url';

  const MA_RELEASE = 'ma:release';

  const MA_DEPLOY = 'ma:deploy';

  const TUGBOAT_REBUILD = 'ma:tugboat-rebuild';

  public string $site = 'prod:massgov';

  const TUGBOAT_REPO = '612e50fcbaa70da92493eef8';
  const CIRCLE_URI = 'https://circleci.com/api/v2/project/github/massgov/openmass/pipeline';

  /**
   * Run Backstop Snapshot at CircleCI; save result for ma:backstop-reference.
   */
  #[CLI\Command(name: self::CI_BACKSTOP_SNAPSHOT, aliases: ['ma-ci-backstop-snapshot'])]
  #[CLI\Option(name: 'target', description: self::TARGET_DESC, suggestedValues: self::TARGET_LIST)]
  #[CLI\Option(name: 'list', description: 'The list you want to run. Recognized values: page, all, post-release. See backstop/backstop.js')]
  #[CLI\Option(name: 'tugboat', description: 'A Tugboat URL which should be used as target. You must also pass \'tugboat\' as target. When omitted, the most recent Preview for the current branch is assumed.')]
  #[CLI\Option(name: 'viewport', description: 'The viewport you want to run.  Recognized values: desktop, tablet, phone. See backstop/backstop.js.')]
  #[CLI\Option(name: 'ci-branch', description: 'The branch that CircleCI should check out at start, default value is "develop"')]
  #[CLI\Option(name: 'cachebuster', description: 'Appends a cache busting query string to URLs of pages to be tested')]
  #[CLI\Usage(name: 'drush ma:ci:backstop-snapshot --target=prod', description: 'Create a backstop snapshot from production.')]
  #[ValidateCircleciToken]
  public function ciBackstopSnapshot(array $options = ['target' => 'prod', 'ci-branch' => 'develop', 'list' => 'all', 'viewport' => 'all', 'cachebuster' => false]): void {
    // If --tugboat is specified without a specific URL, or --tugboat is
    // omitted, automatically determine the preview for the branch.
    if ($options['target'] === 'tugboat') {
      $tugboat_url = $this->getTugboatUrl($options['tugboat'], $options['ci-branch']);
    }
    $stack = $this->getStack();
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $options = [
      'auth' => [$this->getTokenCircle(), ''],
      'json' => [
        'branch' => $options['ci-branch'],
        'parameters' => [
          'webhook' => FALSE,
          'trigger_workflow' => 'backstop_snapshot',
          'target' => $options['target'],
          'list' => $options['list'],
          'viewport' => $options['viewport'],
          'tugboat' => !empty($tugboat_url) ? $tugboat_url : '',
          'cachebuster' => $options['cachebuster'],
        ],
      ],
    ];
    $response = $client->request('POST', self::CIRCLE_URI, $options);
    $code = $response->getStatusCode();
    if ($code >= 400) {
      throw new \Exception('CircleCI API response was a ' . $code . '. Use -v for more Guzzle information.');
    }

    $body = json_decode((string)$response->getBody(), TRUE);
    $this->logger()->success($this->getSuccessMessage($body));
  }

  /**
   * Run Backstop at CircleCI against the screenshots from Backstop Snapshot.
   */
  #[CLI\Command(name: self::CI_BACKSTOP_COMPARE, aliases: ['ma-ci-backstop-compare'])]
  #[CLI\Option(name: 'target', description: self::TARGET_DESC, suggestedValues: self::TARGET_LIST)]
  #[CLI\Option(name: 'list', description: 'The list you want to run. Recognized values: page, all, post-release. See backstop/backstop.js')]
  #[CLI\Option(name: 'tugboat', description: 'A Tugboat URL which should be used as target. You must also pass \'tugboat\' as target. When omitted, the most recent Preview for the current branch is assumed.')]
  #[CLI\Option(name: 'viewport', description: 'The viewport you want to run.  Recognized values: desktop, tablet, phone. See backstop/backstop.js.')]
  #[CLI\Option(name: 'ci-branch', description: 'The branch that CircleCI should check out at start, default value is "develop"')]
  #[CLI\Option(name: 'cachebuster', description: 'Appends a cache busting query string to URLs of pages to be tested')]
  #[CLI\Option(name: 'force-reference', description: 'Forces new reference images to be taken instead of using ones in CircleCI\'s saved artifacts.')]
  #[CLI\Usage(name: 'drush ma:ci:backstop-compare --reference=prod --target=test', description: 'Run backstop in the test environment against the latest production screenshots')]
  #[ValidateCircleciToken]
  public function ciBackstopCompare(array $options = ['reference' => 'prod', 'target' => 'test', 'ci-branch' => 'develop', 'list' => 'all', 'viewport' => 'all', 'cachebuster' => false, 'tugboat' => self::OPT, 'force-reference' => false]): void {
    // If --tugboat is specified without a specific URL, or --tugboat is
    // omitted, automatically determine the preview for the branch.
    if ($options['target'] === 'tugboat') {
      $tugboat_url = $this->getTugboatUrl($options['tugboat'], $options['ci-branch']);
    }
    $stack = $this->getStack();
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $options = [
      'auth' => [$this->getTokenCircle(), ''],
      'json' => [
        'branch' => $options['ci-branch'],
        'parameters' => [
          'webhook' => FALSE,
          'trigger_workflow' => 'backstop_compare',
          'reference' => $options['reference'],
          'target' => $options['target'],
          'list' => $options['list'],
          'viewport' => $options['viewport'],
          'tugboat' => !empty($tugboat_url) ? $tugboat_url : '',
          'cachebuster' => $options['cachebuster'],
          'force-reference' => $options['force-reference'],
        ],
      ],
    ];
    $response = $client->request('POST', self::CIRCLE_URI, $options);
    $code = $response->getStatusCode();
    if ($code >= 400) {
      throw new \Exception('CircleCI API response was a ' . $code . '. Use -v for more Guzzle information.');
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    $this->logger()->success($this->getSuccessMessage($body));
  }

  /**
   * Initiate an on-demand database backup via the Acquia API.
   */
  #[CLI\Command(name: self::MA_BACKUP, aliases: [])]
  #[CLI\Argument(name: 'target', description: self::TARGET_DESC, suggestedValues: self::TARGET_LIST)]
  #[CLI\Usage(name: 'drush ma:backup prod', description: 'Initiate a database backup in production.')]
  public function createBackup($target) {
    $env = $this->siteAliasManager()->getAlias($target);
    $cloudapi = $this->getClient();
    $backup = new DatabaseBackups($cloudapi);
    $response = $backup->create($env->get('uuid'), 'massgov');
    if ($response->message !== 'Creating the backup.') {
      throw new \Exception('Failed to create a backup via Acquia Cloud API.');
    }
    $this->logger()->success('Backup initiated.');
  }

  /**
   * Write the download link for the most recent database backup to stdout.
   */
  #[CLI\Command(name: self::LATEST_BACKUP_URL, aliases: [])]
  #[CLI\Argument(name: 'target', description: self::TARGET_DESC, suggestedValues: self::TARGET_LIST)]
  #[CLI\Argument(name: 'type', description: 'Backup type. Recognized values: ondemand, daily.')]
  #[CLI\Usage(name: 'drush ma:latest-backup-url prod', description: 'Fetch a link to the latest database backup from production.')]
  public function latestBackupUrl($target, $type = null) {
    $env = $this->siteAliasManager()->getAlias($target);
    $cloudapi = $this->getClient();
    $backup = new DatabaseBackups($cloudapi);
    $backups = $backup->getAll($env->get('uuid'), 'massgov');

    // Ignore backups that are still in progress, and of wrong type.
    // The 'use' keyword is described at https://bryce.fisher-fleig.org/blog/php-what-does-function-use-syntax-mean/index.html.
    $backups = array_filter((array)$backups, function($backup) use ($type) {
      return $backup->completedAt > 0 && (is_null($type) || $backup->type == $type);
    });

    // Use the most recent backup.
    $backup = reset($backups);
    if ($backup) {
      $url = $backup->links->download->href;
      if(!str_starts_with($url, Connector::BASE_URI)) {
        throw new \Exception('Backup URL is not hosted on Acquia API. We\'re not sure what to do here.');
      }
      $response = $cloudapi->makeRequest('get', substr($url, strlen(Connector::BASE_URI)), ['allow_redirects' => FALSE]);

      return str_replace('massgov.prod.acquia-sites.com', 'edit.mass.gov', $response->getHeader('Location'));
    }
    throw new \Exception('No usable backups were found.');
  }

  /**
   * Run `ma:deploy` at CircleCI, for better reliability and logging.
   */
  #[CLI\Command(name: self::MA_RELEASE, aliases: [])]
  #[CLI\Argument(name: 'target', description: self::TARGET_DESC)]
  #[CLI\Argument(name: 'git_ref', description: 'Tag or branch to deploy. Must be pushed to Acquia.')]
  #[CLI\Option(name: 'ci-branch', description: 'The branch that CircleCI should check out at start.')]
  #[CLI\Usage(name: 'drush ma:release test tags/build-0.6.1', description: 'Deploy build-0.6.1 tag to the staging environment.')]
  #[ValidateCircleciToken]
  #[OptionsetDeploy]
  public function release($target, $git_ref, array $options = ['ci-branch' => self::REQ]) {
    // For production deployments, prompt the user if they are sure. If they say no, exit.
    if ($target === 'prod') {
      $this->confirmProd();
    }

    // Use our logger - https://stackoverflow.com/questions/32681165/how-do-you-log-all-api-calls-using-guzzle-6.
    $stack = $this->getStack();
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $options = [
      'auth' => [$this->getTokenCircle()],
      'json' => [
        'branch' => $options['ci-branch'] ?: $git_ref,
        'parameters' => [
          'webhook' => FALSE,
          'ma-release' => TRUE,
          'target' => $target,
          'git-ref' => $git_ref,
          'skip-maint' => $options['skip-maint'] ? '--skip-maint' : '',
          'refresh-db' => $options['refresh-db'] ? '--refresh-db' : '',
        ],
      ],
    ];
    $response = $client->request('POST', $this::CIRCLE_URI, $options);
    $code = $response->getStatusCode();
    if ($code >= 400) {
      throw new \Exception('CircleCI API response was a ' . $code . 'Use -v for more Guzzle information.');
    }

    $body = json_decode((string)$response->getBody(), TRUE);
    $this->logger()->success('Pipeline ' . $body['number'] . ' is viewable at https://circleci.com/gh/massgov/openmass.');
  }

  /**
   * Deploy code and database (if needed).
   *
   * Copies Prod DB to target environment, then runs config import, updb,
   * varnish purge, etc.
   */
  #[CLI\Command(name: self::MA_DEPLOY, aliases: ['ma-deploy'])]
  #[CLI\Argument(name: 'target', description: self::TARGET_DESC, suggestedValues: self::TARGET_LIST)]
  #[CLI\Argument(name: 'git_ref', description: 'Tag or branch to deploy. Must be pushed to Acquia.')]
  #[CLI\Usage(name: 'drush ma-deploy test tags/build-0.6.1', description: 'Deploy build-0.6.1 tag to the staging environment.')]
  #[OptionsetDeploy]
  public function deploy(string $target, string $git_ref, array $options) {
    $self = $this->siteAliasManager()->getSelf();

    // For production deployments, prompt the user if they are sure. If they say no, exit.
    $is_prod = ($target === 'prod');
    if ($is_prod) {
      $this->confirmProd();
    }

    $this->logger()->success('Starting deployment of {revision} to {target} at {time}', [
      'target' => $target,
      'revision' => $git_ref,
      'time' => $this->getTimestamp()
    ]);

    $targetRecord = $this->siteAliasManager()->get('@' . $target);

    // Copy database, but only for non-prod deploys and when refresh-db is set.
    if (!$is_prod && $options['refresh-db']) {
      // This section resembles ma-refresh-local --db-prep-only. We don't call that
      // since we can't easily make Cloud API calls from Acquia servers, and we
      // don't need to sanitize here.
      $process = Drush::drush($self, self::LATEST_BACKUP_URL, ['prod']);
      $process->mustRun();
      $url = $process->getOutput();
      $this->logger()->success('Backup URL retrieved.');

      // Download the latest backup.
      // Directory set by https://jira.mass.gov/browse/DP-12823.
      $tmp =  Path::join('/mnt/tmp', $_SERVER['REQUEST_TIME'] . '-db-backup.sql.gz');
      $bash = ['wget', '-q', '--continue', trim($url), "--output-document=$tmp"];
      $process = Drush::siteProcess($targetRecord, $bash);
      $process->mustRun($process->showRealtime());
      $this->logger()->success('Database downloaded from backup.');

      // Drop all tables.
      $process = Drush::drush($targetRecord, 'sql:drop');
      $process->mustRun();
      $this->logger()->success('Dropped all tables.');

      // Import the latest backup.
      // $bash = ['zgrep', '--line-buffered', '-v', '-e', '^INSERT INTO \`cache_', '-e', '^INSERT INTO \`migrate_map_', '-e', "^INSERT INTO \`config_log", '-e', "^INSERT INTO \`key_value_expire", '-e', "^INSERT INTO \`sessions", $tmp, Shell::op('|'), Path::join($targetRecord->root(), '../vendor/bin/drush'), '-r', $targetRecord->root(), '-v', 'sql:cli'];
      // $bash = '-e "^INSERT INTO \`migrate_map_" -e "^INSERT INTO \`config_log" -e "^INSERT INTO \`key_value_expire" -e "^INSERT INTO \`sessions" ' . $tmp . ' | drush -vvv sql:cli';
      $bash = ['cd', $targetRecord->root(), Shell::op('&&'), '../scripts/ma-import-backup', $tmp];
      $process = Drush::siteProcess($targetRecord, $bash);
      $process->disableOutput();
      $process->mustRun();
      $this->logger()->success('Database imported from backup.');

      // Delete tmp file.
      $bash = ['test', '-f', $tmp, Shell::op('&&'), 'rm', $tmp];
      $process = Drush::siteProcess($targetRecord, $bash);
      $process->mustRun();
      $this->logger()->success('Temporary file deleted. ' . $tmp);
    }

    if ($options['skip-maint'] == FALSE) {
      // Turn on Maint mode.
      // @todo change to `drush maint:set` after Drush 11.5 is deployed to Prod.
      $args = array('system.maintenance_mode', 1);
      $state_options = array('input-format' => 'integer');
      $process = Drush::drush($targetRecord, 'state:set', $args, $state_options);
      $process->mustRun();
      $this->logger()->success("Maintenance mode enabled in $target.");
    }

    // We need to set the PHP version before we deploy the code, as the new
    // artifacts may have changes dependent on the PHP version.
    $this->setPhpVersion($targetRecord, self::PHP_VERSION);

    // Deploy the new code.
    $operationResponse = (new Code($this->getClient()))->switch($targetRecord->get('uuid'), $git_ref);
    $href = $operationResponse->links->notification->href;
    /** @noinspection PhpParamsInspection */
    $this->waitForTaskToComplete(basename($href));

    // Run deploy steps.
    $process = Drush::drush($targetRecord, 'deploy', [], ['verbose' => TRUE]);
    $process->mustRun($process->showRealtime());

    $this->purgeSelective($targetRecord);
    $this->logger()->success("Selective Purge enqueued at $target.");

    // Perform a final cache rebuild just in case. Root cause is unknown.
    // @todo Explore removing this in future.
    if ($options['cache-rebuild']) {
      $process = Drush::drush($targetRecord, 'cache:rebuild');
      $process->mustRun();
      $this->logger()->success("Extra cache rebuild completed at $target.");
    }

    if ($options['skip-maint'] == FALSE) {
      // Disable Maintenance mode.
      $args = array('system.maintenance_mode', '0');
      $state_options = ['input-format' => 'integer'];
      $process = Drush::drush($targetRecord, 'state:set', $args, $state_options);
      $process->mustRun();
      $this->logger()->success("Maintenance mode disabled in $target.");
    }

    if ($is_prod) {
      // Log a new deployment at New Relic.
      $this->newRelic($git_ref, getenv('AC_API_USER'), getenv('MASS_NEWRELIC_APPLICATION'), getenv('MASS_NEWRELIC_KEY'));
    }
    $done = $this->getTimestamp();
    $this->io()->success("Deployment completed at {$done}");

    // Process purge queue.
    $process = Drush::drush($targetRecord, 'p:queue-work', [], ['finish' => TRUE, 'verbose' => TRUE]);
    $process->mustRun();
    $this->logger()->success("Purge queue worker complete at $target.");
  }

  /**
   * Rebuild a branch preview at Tugboat.
   */
  #[CLI\Command(name: self::TUGBOAT_REBUILD, aliases: ['ma:tbrb'])]
  #[CLI\Argument(name: 'branch', description: 'A branch name')]
  public function tugboatRebuild(string $branch) {
    $stack = $this->getStack();
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $options = [
      'headers' => ["Authorization" => 'Bearer ' . getenv('TUGBOAT_ACCESS_TOKEN')],
      'json' => [
        'children' => TRUE,
        'force' => TRUE,
      ],
    ];
    // @todo deploy the token.
    if (!$id = $this->getTugboatPreviewForBranch($branch)) {
      $this->logger()->warning('Tugboat preview for develop not found.');
      return;
    }
    $response = $client->request('POST', "https://api.tugboat.qa/v3/previews/$id/rebuild", $options);
    $code = $response->getStatusCode();
    if ($code >= 400) {
      throw new \Exception('Tugboat API response was a ' . $code . '. Use -v for more Guzzle information.');
    }

    // $body = json_decode((string)$response->getBody(), TRUE);
    $this->logger()->success('Tugboat preview rebuild successful id=' . $id);
  }

  protected function getClient() {
    return Client::factory($this->getConnector());
  }

  protected function getConnector() {
    $config = [
      // Easiest way to provide creds is in a .env file. See /.env.example
      'key' => getenv('AC_API2_KEY'),
      'secret' => getenv('AC_API2_SECRET'),
    ];
    return new Connector($config);
  }

  /**
   * Validate the target name.
   */
  #[CLI\Hook(type: HookManager::ARGUMENT_VALIDATOR)]
  public function validate(CommandData $commandData) {
    if (!$commandData->input()->hasArgument('target')) {
      return;
    }
    $target = $commandData->input()->getArgument('target');
    if (!in_array($target, self::TARGET_LIST)) {
      throw new \Exception('Invalid argument: target. \nYou entered "' . $target . '". Target must be one of: ' . implode(', ', self::TARGET_LIST));
    }
  }

  /**
   * Lookup the Preview corresponding to the specified branch.
   *
   * @param $branch
   * @param $property
   *
   * @return ?string
   */
  public function getTugboatPreviewForBranch(string $branch, string $property = 'id'): ?string {
    // Get all previews.
    $stack = $this->getStack();
    $client = new \GuzzleHttp\Client(['handler' => $stack]);
    $options = [
      'headers' => ["Authorization" => 'Bearer ' . getenv('TUGBOAT_ACCESS_TOKEN')],
    ];
    $repo_id = self::TUGBOAT_REPO;
    $response = $client->request('GET', "https://api.tugboat.qa/v3/repos/$repo_id/previews", $options);
    $code = $response->getStatusCode();
    if ($code >= 400) {
      throw new \Exception('Tugboat API response was a ' . $code . '. Use -v for more Guzzle information.');
    }

    $previews = json_decode((string)$response->getBody(), TRUE);
    foreach ($previews as $preview) {
      if ($preview['provider_ref']['head']['ref'] == $branch || $preview->provider_id == "refs/heads/$branch") {
        $this->logger()->info("Fetched preview for branch $branch.");
        $return = $preview[$property];
        break;
      }
    }

    return $return ?: NULL;
  }

  public function purgeSelective(SiteAlias|bool $targetRecord) {
    // Enqueue purging of QAG pages.
    $sql = "SELECT nid FROM node_field_data WHERE title LIKE '%_QAG%'";
    $process = Drush::drush($targetRecord, 'sql:query', [$sql], ['verbose' => TRUE]);
    $process->mustRun();
    $out = $process->getOutput();
    $nids = array_filter(explode("\n", $out));
    foreach ($nids as $nid) {
      $tags[] = "node:$nid";
    }
    $process = Drush::drush($targetRecord, 'cache:tags', [implode(',', $tags)], ['verbose' => TRUE]);
    $process->mustRun();

    // Enqueue purging of notable URLs. Don't use tags to avoid over-purging.
    // Empty path is the homepage
    $paths = ['', '/orgs/office-of-the-governor', '/media/1268726'];
    foreach ($paths as $path) {
      $process = Drush::drush($targetRecord, 'ev', ["\Drupal::service('manual_purger')->purgePath('$path');"], ['verbose' => TRUE]);
      $process->mustRun();
    }
  }

  /**
   * Validate the presence of a CircleCI token.
   */
  #[CLI\Hook(type: HookManager::ARGUMENT_VALIDATOR, selector: self::VALIDATE_CIRCLECI_TOKEN)]
  public function validateCircleCIToken() {
    if (!$this->getTokenCircle()) {
      throw new \Exception('Missing CIRCLECI_PERSONAL_API_TOKEN. See .env.example for more details.');
    }
  }

  /**
   * Loop and re-check until a given task is complete.
   *
   * @param str $uuid
   *   The Notification UUID.
   *
   * @throws \Exception
   */
  public function waitForTaskToComplete($uuid) {
    $client = $this->getClient();

    while (TRUE) {
      $notification = (new Notifications($client))->get($uuid);
      if ($notification->status == 'completed') {
        $this->logger()->success(dt('!desc is complete: Notification !uuid.', ['!desc' => $notification->description, '!uuid' => $uuid]));
        break;
      }
      elseif ($notification->status == 'failed') {
        throw new \Exception(dt("!desc - Notification !uuid failed.", ['!desc' => $notification->description, '!uuid' => $uuid]));
      }
      else {
        $this->logger()->notice(dt('!desc: Will re-check Notification !uuid for completion in 5 seconds.', ['!desc' => $notification->description, '!uuid' => $uuid]));
        sleep(5);
      }
    }
  }

  /**
   * Post new deployment to New Relic.
   */
  public function newRelic($git_ref, $email, $application, $api_key) {
    $cmd = <<<EOT
curl -X POST 'https://api.newrelic.com/v2/applications/$application/deployments.json' \
     -H 'Api-Key:$api_key' -i \
     -H 'Content-Type: application/json' \
     -d \
'{
  "deployment": {
    "revision": "$git_ref",
    "changelog": "",
    "description": "",
    "user": "$email"
  }
}'
EOT;
    if (!Drush::shell($cmd)) {
      $this->logger()->warning('Failed to create a Deployment at New Relic');
    }
  }

  /**
   * Get a string representing the current time in EST.
   *
   * @return string
   */
  private function getTimestamp() {
    return (new \DateTime('now', new \DateTimeZone('America/New_York')))->format('Y-m-d g:i:s A');
  }

  /**
   * @return string|void
   * @throws \Drush\Exceptions\UserAbortException
   */
  protected function confirmProd(): void
  {
    if (!$this->io()
      ->confirm('This is a Production deployment. Are you damn sure?')) {
      throw new UserAbortException();
    }
  }

  /**
   * Use our logger - https://stackoverflow.com/questions/32681165/how-do-you-log-all-api-calls-using-guzzle-6.
   *
   * @return \GuzzleHttp\HandlerStack
   */
  protected function getStack(): \GuzzleHttp\HandlerStack {
    $stack = HandlerStack::create();
    $stack->push(Middleware::log($this->logger(), new MessageFormatter(Drush::verbose() ? MessageFormatter::DEBUG : MessageFormatter::SHORT)));
    return $stack;
  }

  /**
   * @return array|false|string
   */
  protected function getTokenCircle() {
    return getenv('CIRCLECI_PERSONAL_API_TOKEN');
  }

  /**
   * Return success message about how to view a Pipeline at CircleCI.
   *
   * @param array $body
   *
   * @return string
   */
  private function getSuccessMessage($body): string {
    return 'Pipeline ' . $body['number'] . ' is viewable at https://circleci.com/gh/massgov/openmass.';
  }

  /**
   * Set the PHP version on a given Acquia environment.
   *
   * Acquia treats the PHP version as a setting in the environment, and not
   * configuration as a part of a build.
   *
   * @param \Consolidation\SiteAlias\SiteAlias $targetRecord
   * @param string $version
   *
   * @return void
   * @throws \Exception
   */
  private function setPhpVersion(SiteAlias $targetRecord, string $version): void {
    $environmentUuid = $targetRecord->get('uuid');
    $client = $this->getClient();

    $currentVersion = (new Environments($client))->get($environmentUuid)
      ->configuration
      ->php
      ->version;

    $this->logger()->info("{name} is currently set to PHP {version}", [
      'name' => $targetRecord->name(),
      'version'=> $currentVersion,
    ]);

    if ($version !== $currentVersion) {
      $this->logger()->info("Switching {name} to PHP {version}", [
        'name' => $targetRecord->name(),
        'version'=> $version,
      ]);
      $modifyResponse = (new Environments($this->getClient()))->update($environmentUuid, ['version' => $version]);
      /** @noinspection PhpParamsInspection */
      $this->waitForTaskToComplete(basename($modifyResponse->links->notification->href));
    }
  }

  /**
   * Return the Drush logger, and fail if it does not exist.
   *
   * The parent logger() method is typehinted to optionally return a
   * DrushLoggerManager. That means that every call to logger() should check
   * against NULL before calling methods. Rather than rewrite all of our typical
   * Drush code that in practice should only fail if things are Horribly Broken,
   * this method implements a stricter typehint and throws a useful exception if
   * a logger is not set.
   *
   * @throws \RuntimeException
   *   Thrown when a Drush logger is not set.
   *
   * @return \Drush\Log\DrushLoggerManager
   */
  protected function logger(): DrushLoggerManager {
    $logger = parent::logger();
    if (!$logger) {
      throw new \RuntimeException('No Drush logger is available, but one should always be present.');
    }

    return $logger;
  }

  /**
   * Fetch the tugboat URL based on --target and --ci-branch.
   *
   * @param mixed $tugboat_url_option
   *   The value passed in --tugboat=<value>, TRUE when passing --tugboat, and
   *   FALSE when --tugboat is omitted.
   * @param string $ci_branch
   *   The branch being built.
   *
   * @return string
   *   The Tugboat URL to test against.
   */
  private function getTugboatUrl($tugboat_url_option, string $ci_branch): string {
    if ($tugboat_url_option === TRUE || empty($tugboat_url_option)) {
      $branch = $ci_branch;
      if ($branch === 'develop') {
        $process = $this->processManager()
          ->shell('git rev-parse --abbrev-ref HEAD');
        $branch = trim($process->mustRun()->getOutput());
        if (empty($branch)) {
          throw new \RuntimeException('Unable to determine current branch. Pass --tugboat option.');
        }
      }
      $tugboat_url_option = $this->getTugboatPreviewForBranch($branch, 'url');
      if (empty($tugboat_url_option)) {
        throw new \RuntimeException('Unable to find a matching Tugboat preview. Pass --tugboat option.');
      }
    }

    return $tugboat_url_option;
  }

}
