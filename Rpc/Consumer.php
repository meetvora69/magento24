<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\MessageQueue\Rpc;

use Magento\Framework\MessageQueue\Config\Converter as MessageQueueConfigConverter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\ConsumerInterface;
use Magento\Framework\MessageQueue\ConsumerConfigurationInterface;
use Magento\Framework\MessageQueue\CallbackInvoker;
use Magento\Framework\MessageQueue\MessageEncoder;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\QueueInterface;


/**
 * A MessageQueue Consumer to handle receiving, processing and replying to an RPC message.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Consumer implements ConsumerInterface
{
    /**
     * @var ConsumerConfigurationInterface
     */
    private $configuration;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var MessageEncoder
     */
    private $messageEncoder;

    /**
     * @var CallbackInvoker
     */
    private $invoker;

    /**
     * @var \Magento\Framework\MessageQueue\PublisherPool
     */
    private $publisherPool;

    /**
     * @var \Magento\Framework\MessageQueue\QueueRepository
     */
    private $queueRepository;

    /**
     * @var \Magento\Framework\MessageQueue\ConfigInterface
     */
    private $queueConfig;

    /**
     * Initialize dependencies.
     *
     * @param CallbackInvoker $invoker
     * @param MessageEncoder $messageEncoder
     * @param ResourceConnection $resource
     * @param ConsumerConfigurationInterface $configuration
     * @param \Magento\Framework\MessageQueue\QueueRepository $queueRepository
     * @param \Magento\Framework\MessageQueue\PublisherPool $publisherPool
     * @param \Magento\Framework\MessageQueue\ConfigInterface $queueConfig
     */
    public function __construct(
        CallbackInvoker $invoker,
        MessageEncoder $messageEncoder,
        ResourceConnection $resource,
        ConsumerConfigurationInterface $configuration,
        \Magento\Framework\MessageQueue\QueueRepository $queueRepository,
        \Magento\Framework\MessageQueue\PublisherPool $publisherPool,
        \Magento\Framework\MessageQueue\ConfigInterface $queueConfig
    ) {
        $this->invoker = $invoker;
        $this->messageEncoder = $messageEncoder;
        $this->resource = $resource;
        $this->configuration = $configuration;
        $this->publisherPool = $publisherPool;
        $this->queueRepository = $queueRepository;
        $this->queueConfig = $queueConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function process($maxNumberOfMessages = null)
    {
        $queue = $this->configuration->getQueue();

        if (!isset($maxNumberOfMessages)) {
            $queue->subscribe($this->getTransactionCallback($queue));
        } else {
            $this->invoker->invoke($queue, $maxNumberOfMessages, $this->getTransactionCallback($queue));
        }
    }

    /**
     * Decode message and invoke callback method
     *
     * @param EnvelopeInterface $message
     * @return string
     * @throws LocalizedException
     */
    private function dispatchMessage(EnvelopeInterface $message)
    {
        $properties = $message->getProperties();
        $topicName = $properties['topic_name'];
        $handlers = $this->configuration->getHandlers();
        $decodedMessage = $this->messageEncoder->decode($topicName, $message->getBody());
        if (isset($decodedMessage)) {
            $messageSchemaType = $this->configuration->getMessageSchemaType($topicName);
            if ($messageSchemaType == MessageQueueConfigConverter::TOPIC_SCHEMA_TYPE_METHOD) {
                foreach ($handlers as $callback) {
                    $result = call_user_func_array($callback, $decodedMessage);
                    if (isset($result)) {
                        return $this->messageEncoder->encode($topicName, $result, false);
                    } else {
                        throw new LocalizedException(__('No reply message resulted in RPC.'));
                    }
                }
            } else {
                foreach ($handlers as $callback) {
                    $result = call_user_func($callback, $decodedMessage);
                    if (isset($result)) {
                        return $this->messageEncoder->encode($topicName, $result, false);
                    } else {
                        throw new LocalizedException(__('No reply message resulted in RPC.'));
                    }
                }
            }
        }
        return null;
    }

    /**
     * Send RPC response message
     *
     * @param EnvelopeInterface $envelope
     * @param string $replyMessage
     * @return void
     */
    private function sendResponse(EnvelopeInterface $envelope, $replyMessage)
    {
        $messageProperties = $envelope->getProperties();
        $connectionName = $this->queueConfig->getConnectionByTopic($messageProperties['topic_name']);
        $queue = $this->queueRepository->get($connectionName, $messageProperties['reply_to']);
        $queue->push($envelope, $replyMessage);
    }

    /**
     * @param QueueInterface $queue
     * @return \Closure
     */
    private function getTransactionCallback(QueueInterface $queue)
    {
        return function (EnvelopeInterface $message) use ($queue) {
            try {
                $this->resource->getConnection()->beginTransaction();
                $replyMessages = $this->dispatchMessage($message);
                $this->sendResponse($message, $replyMessages);
                $queue->acknowledge($message);
                $this->resource->getConnection()->commit();
            } catch (\Magento\Framework\MessageQueue\ConnectionLostException $e) {
                $this->resource->getConnection()->rollBack();
            } catch (\Exception $e) {
                $this->resource->getConnection()->rollBack();
                $queue->reject($message);
            }
        };
    }
}
