<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Transaction;

use SplQueue;
use Predis\BasicClientInterface;
use Predis\ClientException;
use Predis\ClientInterface;
use Predis\CommunicationException;
use Predis\ExecutableContextInterface;
use Predis\NotSupportedException;
use Predis\Response;
use Predis\Command\CommandInterface;
use Predis\Connection\AggregatedConnectionInterface;
use Predis\Protocol\ProtocolException;

/**
 * Client-side abstraction of a Redis transaction based on MULTI / EXEC.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class MultiExec implements BasicClientInterface, ExecutableContextInterface
{
    private $state;
    private $canWatch;

    protected $client;
    protected $options;
    protected $commands;

    /**
     * @param ClientInterface $client Client instance used by the transaction.
     * @param array $options Initialization options.
     */
    public function __construct(ClientInterface $client, array $options = null)
    {
        $this->checkCapabilities($client);

        $this->options = $options ?: array();
        $this->client = $client;
        $this->state = new MultiExecState();

        $this->reset();
    }

    /**
     * Checks if the passed client instance satisfies the required conditions
     * needed to initialize the transaction object.
     *
     * @param ClientInterface $client Client instance used by the transaction object.
     */
    private function checkCapabilities(ClientInterface $client)
    {
        if ($client->getConnection() instanceof AggregatedConnectionInterface) {
            throw new NotSupportedException(
                'Cannot initialize a MULTI/EXEC transaction when using aggregated connections'
            );
        }

        $profile = $client->getProfile();

        if ($profile->supportsCommands(array('multi', 'exec', 'discard')) === false) {
            throw new NotSupportedException(
                'The current profile does not support MULTI, EXEC and DISCARD'
            );
        }

        $this->canWatch = $profile->supportsCommands(array('watch', 'unwatch'));
    }

    /**
     * Checks if WATCH and UNWATCH are supported by the server profile.
     */
    private function isWatchSupported()
    {
        if ($this->canWatch === false) {
            throw new NotSupportedException('The current profile does not support WATCH and UNWATCH');
        }
    }

    /**
     * Resets the state of a transaction.
     */
    protected function reset()
    {
        $this->state->reset();
        $this->commands = new SplQueue();
    }

    /**
     * Initializes a new transaction.
     */
    protected function initialize()
    {
        if ($this->state->isInitialized()) {
            return;
        }

        $options = $this->options;

        if (isset($options['cas']) && $options['cas']) {
            $this->state->flag(MultiExecState::CAS);
        }
        if (isset($options['watch'])) {
            $this->watch($options['watch']);
        }

        $cas = $this->state->isCAS();
        $discarded = $this->state->isDiscarded();

        if (!$cas || ($cas && $discarded)) {
            $this->client->multi();

            if ($discarded) {
                $this->state->unflag(MultiExecState::CAS);
            }
        }

        $this->state->unflag(MultiExecState::DISCARDED);
        $this->state->flag(MultiExecState::INITIALIZED);
    }

    /**
     * Dynamically invokes a Redis command with the specified arguments.
     *
     * @param string $method Command ID.
     * @param array $arguments Arguments for the command.
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        $command = $this->client->createCommand($method, $arguments);
        $response = $this->executeCommand($command);

        return $response;
    }

    /**
     * Executes the specified Redis command.
     *
     * @param CommandInterface $command A Redis command.
     * @return mixed
     */
    public function executeCommand(CommandInterface $command)
    {
        $this->initialize();
        $response = $this->client->executeCommand($command);

        if ($this->state->isCAS()) {
            return $response;
        }

        if (!$response instanceof Response\StatusQueued) {
            $this->onProtocolError('The server did not respond with a QUEUED status reply');
        }

        $this->commands->enqueue($command);

        return $this;
    }

    /**
     * Executes WATCH on one or more keys.
     *
     * @param string|array $keys One or more keys.
     * @return mixed
     */
    public function watch($keys)
    {
        $this->isWatchSupported();

        if ($this->state->isWatchAllowed()) {
            throw new ClientException('WATCH after MULTI is not allowed');
        }

        $reply = $this->client->watch($keys);
        $this->state->flag(MultiExecState::WATCH);

        return $reply;
    }

    /**
     * Finalizes the transaction on the server by executing MULTI on the server.
     *
     * @return MultiExec
     */
    public function multi()
    {
        if ($this->state->check(MultiExecState::INITIALIZED | MultiExecState::CAS)) {
            $this->state->unflag(MultiExecState::CAS);
            $this->client->multi();
        } else {
            $this->initialize();
        }

        return $this;
    }

    /**
     * Executes UNWATCH.
     *
     * @return MultiExec
     */
    public function unwatch()
    {
        $this->isWatchSupported();
        $this->state->unflag(MultiExecState::WATCH);
        $this->__call('unwatch', array());

        return $this;
    }

    /**
     * Resets a transaction by UNWATCHing the keys that are being WATCHed and
     * DISCARDing the pending commands that have been already sent to the server.
     *
     * @return MultiExec
     */
    public function discard()
    {
        if ($this->state->isInitialized()) {
            $command = $this->state->isCAS() ? 'unwatch' : 'discard';
            $this->client->$command();
            $this->reset();
            $this->state->flag(MultiExecState::DISCARDED);
        }

        return $this;
    }

    /**
     * Executes the whole transaction.
     *
     * @return mixed
     */
    public function exec()
    {
        return $this->execute();
    }

    /**
     * Checks the state of the transaction before execution.
     *
     * @param mixed $callable Callback for execution.
     */
    private function checkBeforeExecution($callable)
    {
        if ($this->state->isExecuting()) {
            throw new ClientException("Cannot invoke 'execute' or 'exec' inside an active client transaction block");
        }

        if ($callable) {
            if (!is_callable($callable)) {
                throw new \InvalidArgumentException('Argument passed must be a callable object');
            }

            if (!$this->commands->isEmpty()) {
                $this->discard();
                throw new ClientException('Cannot execute a transaction block after using fluent interface');
            }
        }

        if (isset($this->options['retry']) && !isset($callable)) {
            $this->discard();
            throw new \InvalidArgumentException('Automatic retries can be used only when a transaction block is provided');
        }
    }

    /**
     * Handles the actual execution of the whole transaction.
     *
     * @param mixed $callable Optional callback for execution.
     * @return array
     */
    public function execute($callable = null)
    {
        $this->checkBeforeExecution($callable);

        $reply = null;
        $values = array();
        $attempts = isset($this->options['retry']) ? (int) $this->options['retry'] : 0;

        do {
            if ($callable !== null) {
                $this->executeTransactionBlock($callable);
            }

            if ($this->commands->isEmpty()) {
                if ($this->state->isWatching()) {
                    $this->discard();
                }

                return;
            }

            $reply = $this->client->exec();

            if ($reply === null) {
                if ($attempts === 0) {
                    $message = 'The current transaction has been aborted by the server';
                    throw new AbortedMultiExecException($this, $message);
                }

                $this->reset();

                if (isset($this->options['on_retry']) && is_callable($this->options['on_retry'])) {
                    call_user_func($this->options['on_retry'], $this, $attempts);
                }

                continue;
            }

            break;
        } while ($attempts-- > 0);

        $commands = $this->commands;
        $size = count($reply);

        if ($size !== count($commands)) {
            $this->onProtocolError("EXEC returned an unexpected number of replies");
        }

        $clientOpts = $this->client->getOptions();
        $useExceptions = isset($clientOpts->exceptions) ? $clientOpts->exceptions : true;

        for ($i = 0; $i < $size; $i++) {
            $commandReply = $reply[$i];

            if ($commandReply instanceof Response\ErrorInterface && $useExceptions) {
                $message = $commandReply->getMessage();
                throw new Response\ServerException($message);
            }

            $values[$i] = $commands->dequeue()->parseResponse($commandReply);
        }

        return $values;
    }

    /**
     * Passes the current transaction object to a callable block for execution.
     *
     * @param mixed $callable Callback.
     */
    protected function executeTransactionBlock($callable)
    {
        $blockException = null;
        $this->state->flag(MultiExecState::INSIDEBLOCK);

        try {
            call_user_func($callable, $this);
        } catch (CommunicationException $exception) {
            $blockException = $exception;
        } catch (Response\ServerException $exception) {
            $blockException = $exception;
        } catch (\Exception $exception) {
            $blockException = $exception;
            $this->discard();
        }

        $this->state->unflag(MultiExecState::INSIDEBLOCK);

        if ($blockException !== null) {
            throw $blockException;
        }
    }

    /**
     * Helper method that handles protocol errors encountered inside a transaction.
     *
     * @param string $message Error message.
     */
    private function onProtocolError($message)
    {
        // Since a MULTI/EXEC block cannot be initialized when using aggregated
        // connections, we can safely assume that Predis\Client::getConnection()
        // will always return an instance of Predis\Connection\SingleConnectionInterface.
        CommunicationException::handle(new ProtocolException(
            $this->client->getConnection(), $message
        ));
    }
}
