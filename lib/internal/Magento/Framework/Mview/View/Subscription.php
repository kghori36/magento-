<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Mview\View;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\Ddl\TriggerFactory;
use Magento\Framework\Mview\ViewInterface;

/**
 * Mview subscription.
 */
class Subscription implements SubscriptionInterface
{
    /**
     * Database connection
     *
     * @var AdapterInterface
     */
    protected $connection;

    /**
     * @var TriggerFactory
     */
    protected $triggerFactory;

    /**
     * @var CollectionInterface
     */
    protected $viewCollection;

    /**
     * @var string
     */
    protected $view;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $columnName;

    /**
     * List of views linked to the same entity as the current view
     *
     * @var array
     */
    protected $linkedViews = [];

    /**
     * List of columns that can be updated in any subscribed table
     * without creating a new change log entry
     *
     * @var array
     */
    private $ignoredUpdateColumns;

    /**
     * List of columns that can be updated in a specific subscribed table
     * for a specific view without creating a new change log entry
     */
    private $ignoredUpdateColumnsBySubscription = [];

    /**
     * @var Resource
     */
    protected $resource;

    /**
     * @param ResourceConnection $resource
     * @param TriggerFactory $triggerFactory
     * @param CollectionInterface $viewCollection
     * @param ViewInterface $view
     * @param string $tableName
     * @param string $columnName
     * @param array $ignoredUpdateColumns
     * @param array $ignoredUpdateColumnsBySubscription
     */
    public function __construct(
        ResourceConnection $resource,
        TriggerFactory $triggerFactory,
        CollectionInterface $viewCollection,
        ViewInterface $view,
        $tableName,
        $columnName,
        $ignoredUpdateColumns = [],
        $ignoredUpdateColumnsBySubscription = []
    ) {
        $this->connection = $resource->getConnection();
        $this->triggerFactory = $triggerFactory;
        $this->viewCollection = $viewCollection;
        $this->view = $view;
        $this->tableName = $tableName;
        $this->columnName = $columnName;
        $this->resource = $resource;
        $this->ignoredUpdateColumns = $ignoredUpdateColumns;
        $this->ignoredUpdateColumnsBySubscription = $ignoredUpdateColumnsBySubscription;
    }

    /**
     * Create subscription
     *
     * @return SubscriptionInterface
     */
    public function create()
    {
        foreach (Trigger::getListOfEvents() as $event) {
            $triggerName = $this->getAfterEventTriggerName($event);
            /** @var Trigger $trigger */
            $trigger = $this->triggerFactory->create()
                ->setName($triggerName)
                ->setTime(Trigger::TIME_AFTER)
                ->setEvent($event)
                ->setTable($this->resource->getTableName($this->tableName));

            $trigger->addStatement($this->buildStatement($event, $this->getView()));

            // Add statements for linked views
            foreach ($this->getLinkedViews() as $view) {
                /** @var ViewInterface $view */
                $trigger->addStatement($this->buildStatement($event, $view));
            }

            $this->connection->dropTrigger($trigger->getName());
            $this->connection->createTrigger($trigger);
        }

        return $this;
    }

    /**
     * Remove subscription
     *
     * @return SubscriptionInterface
     */
    public function remove()
    {
        foreach (Trigger::getListOfEvents() as $event) {
            $triggerName = $this->getAfterEventTriggerName($event);
            /** @var Trigger $trigger */
            $trigger = $this->triggerFactory->create()
                ->setName($triggerName)
                ->setTime(Trigger::TIME_AFTER)
                ->setEvent($event)
                ->setTable($this->resource->getTableName($this->getTableName()));

            // Add statements for linked views
            foreach ($this->getLinkedViews() as $view) {
                /** @var ViewInterface $view */
                $trigger->addStatement($this->buildStatement($event, $view));
            }

            $this->connection->dropTrigger($trigger->getName());

            // Re-create trigger if trigger used by linked views
            if ($trigger->getStatements()) {
                $this->connection->createTrigger($trigger);
            }
        }

        return $this;
    }

    /**
     * Retrieve list of linked views
     *
     * @return array
     */
    protected function getLinkedViews()
    {
        if (!$this->linkedViews) {
            $viewList = $this->viewCollection->getViewsByStateMode(StateInterface::MODE_ENABLED);

            foreach ($viewList as $view) {
                /** @var ViewInterface $view */
                // Skip the current view
                if ($view->getId() == $this->getView()->getId()) {
                    continue;
                }
                // Search in view subscriptions
                foreach ($view->getSubscriptions() as $subscription) {
                    if ($subscription['name'] != $this->getTableName()) {
                        continue;
                    }
                    $this->linkedViews[] = $view;
                }
            }
        }
        return $this->linkedViews;
    }

    /**
     * Build trigger statement for INSERT, UPDATE, DELETE events
     *
     * @param string $event
     * @param ViewInterface $view
     * @return string
     */
    protected function buildStatement(string $event, ViewInterface $view): string
    {
        $column = $this->getSubscriptionColumn($view);
        $changelog = $view->getChangelog();

        switch ($event) {
            case Trigger::EVENT_INSERT:
                $trigger = "INSERT IGNORE INTO %s (%s) VALUES (NEW.%s);";
                break;
            case Trigger::EVENT_UPDATE:
                $tableName = $this->resource->getTableName($this->getTableName());
                $trigger = "INSERT IGNORE INTO %s (%s) VALUES (NEW.%s);";
                if ($this->connection->isTableExists($tableName) &&
                    $describe = $this->connection->describeTable($tableName)
                ) {
                    $columnNames = array_column($describe, 'COLUMN_NAME');
                    $ignoredColumnsBySubscription = array_filter(
                        $this->ignoredUpdateColumnsBySubscription[$changelog->getViewId()][$this->getTableName()] ?? []
                    );
                    $ignoredColumns = array_merge(
                        $this->ignoredUpdateColumns,
                        array_keys($ignoredColumnsBySubscription)
                    );
                    $columnNames = array_diff($columnNames, $ignoredColumns);
                    if ($columnNames) {
                        $columns = [];
                        foreach ($columnNames as $columnName) {
                            $columns[] = sprintf(
                                'NOT(NEW.%1$s <=> OLD.%1$s)',
                                $this->connection->quoteIdentifier($columnName)
                            );
                        }
                        $trigger = sprintf(
                            "IF (%s) THEN %s END IF;",
                            implode(' OR ', $columns),
                            $trigger
                        );
                    }
                }
                break;
            case Trigger::EVENT_DELETE:
                $trigger = "INSERT IGNORE INTO %s (%s) VALUES (OLD.%s);";
                break;
            default:
                return '';
        }
        return sprintf(
            $trigger,
            $this->connection->quoteIdentifier($this->resource->getTableName($changelog->getName())),
            $this->connection->quoteIdentifier($changelog->getColumnName()),
            $this->connection->quoteIdentifier($column)
        );
    }

    /**
     * Returns subscription column name by view
     *
     * @param ViewInterface $view
     * @return string
     */
    private function getSubscriptionColumn(ViewInterface $view): string
    {
        $subscriptions = $view->getSubscriptions();
        if (!isset($subscriptions[$this->getTableName()]['column'])) {
            throw new \RuntimeException(sprintf('Column name for view with id "%s" doesn\'t exist', $view->getId()));
        }

        return $subscriptions[$this->getTableName()]['column'];
    }

    /**
     * Build an "after" event for the given table and event
     *
     * @param string $event The DB level event, like "update" or "insert"
     *
     * @return string
     */
    private function getAfterEventTriggerName($event)
    {
        return $this->resource->getTriggerName(
            $this->resource->getTableName($this->getTableName()),
            Trigger::TIME_AFTER,
            $event
        );
    }

    /**
     * Retrieve View related to subscription
     *
     * @return ViewInterface
     * @codeCoverageIgnore
     */
    public function getView()
    {
        return $this->view;
    }

    /**
     * Retrieve table name
     *
     * @return string
     * @codeCoverageIgnore
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * Retrieve table column name
     *
     * @return string
     * @codeCoverageIgnore
     */
    public function getColumnName()
    {
        return $this->columnName;
    }
}
