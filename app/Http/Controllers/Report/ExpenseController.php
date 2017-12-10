<?php
/**
 * ExpenseController.php
 * Copyright (c) 2017 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Report;

use Carbon\Carbon;
use FireflyIII\Helpers\Collector\JournalCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\TransactionType;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\CacheProperties;
use Illuminate\Support\Collection;


/**
 * Class ExpenseController
 */
class ExpenseController extends Controller
{
    /** @var AccountRepositoryInterface */
    protected $accountRepository;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        // translations:
        $this->middleware(
            function ($request, $next) {
                $this->accountRepository = app(AccountRepositoryInterface::class);

                return $next($request);
            }
        );
    }

    /**
     * @param Collection $accounts
     * @param Collection $expense
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     * @throws \Throwable
     */
    public function budget(Collection $accounts, Collection $expense, Carbon $start, Carbon $end)
    {
        // Properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('expense-budget');
        $cache->addProperty($accounts->pluck('id')->toArray());
        $cache->addProperty($expense->pluck('id')->toArray());
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }
        $combined = $this->combineAccounts($expense);
        $all      = new Collection;
        foreach ($combined as $name => $combi) {
            $all = $all->merge($combi);
        }
        // now find spent / earned:
        $spent = $this->spentByBudget($accounts, $all, $start, $end);
        // do some merging to get the budget info ready.
        $together = [];
        foreach ($spent as $budgetId => $spentInfo) {
            if (!isset($together[$budgetId])) {
                $together[$budgetId]['spent'] = $spentInfo;
                // get category info:
                $first                         = reset($spentInfo);
                $together[$budgetId]['budget'] = $first['budget'];
            }
        }

        $result = view('reports.partials.exp-budgets', compact('together'))->render();
        $cache->store($result);

        return $result;
    }

    /**
     * @param Collection $accounts
     * @param Collection $expense
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     * @throws \Throwable
     */
    public function category(Collection $accounts, Collection $expense, Carbon $start, Carbon $end)
    {
        // Properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('expense-category');
        $cache->addProperty($accounts->pluck('id')->toArray());
        $cache->addProperty($expense->pluck('id')->toArray());
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }
        $combined = $this->combineAccounts($expense);
        $all      = new Collection;
        foreach ($combined as $name => $combi) {
            $all = $all->merge($combi);
        }
        // now find spent / earned:
        $spent  = $this->spentByCategory($accounts, $all, $start, $end);
        $earned = $this->earnedByCategory($accounts, $all, $start, $end);

        // join arrays somehow:
        $together = [];
        foreach ($spent as $categoryId => $spentInfo) {
            if (!isset($together[$categoryId])) {
                $together[$categoryId]['spent'] = $spentInfo;
                // get category info:
                $first                             = reset($spentInfo);
                $together[$categoryId]['category'] = $first['category'];
            }
        }

        foreach ($earned as $categoryId => $earnedInfo) {
            if (!isset($together[$categoryId])) {
                $together[$categoryId]['earned'] = $earnedInfo;
                // get category info:
                $first                             = reset($earnedInfo);
                $together[$categoryId]['category'] = $first['category'];
            }
        }

        $result = view('reports.partials.exp-categories', compact('together'))->render();
        $cache->store($result);

        return $result;
    }

    /**
     * @param Collection $accounts
     * @param Collection $expense
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return array|mixed|string
     * @throws \Throwable
     */
    public function spent(Collection $accounts, Collection $expense, Carbon $start, Carbon $end)
    {
        // chart properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('expense-spent');
        $cache->addProperty($accounts->pluck('id')->toArray());
        $cache->addProperty($expense->pluck('id')->toArray());
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        $combined = $this->combineAccounts($expense);
        $result   = [];

        foreach ($combined as $name => $combi) {
            /**
             * @var string     $name
             * @var Collection $combi
             */
            $spent         = $this->spentInPeriod($accounts, $combi, $start, $end);
            $earned        = $this->earnedInPeriod($accounts, $combi, $start, $end);
            $result[$name] = [
                'spent'  => $spent,
                'earned' => $earned,
            ];
        }
        $result = view('reports.partials.exp-not-grouped', compact('result'))->render();
        $cache->store($result);

        return $result;
        // for period, get spent and earned for each account (by name)

    }

    /**
     * @param Collection $accounts
     * @param Collection $expense
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return array|mixed|string
     * @throws \Throwable
     */
    public function spentGrouped(Collection $accounts, Collection $expense, Carbon $start, Carbon $end)
    {
        // Properties for cache:
        $cache = new CacheProperties;
        $cache->addProperty($start);
        $cache->addProperty($end);
        $cache->addProperty('expense-spent-grouped');
        $cache->addProperty($accounts->pluck('id')->toArray());
        $cache->addProperty($expense->pluck('id')->toArray());
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        $combined = $this->combineAccounts($expense);
        $format   = app('navigation')->preferredRangeFormat($start, $end);
        $result   = [];

        foreach ($combined as $name => $combi) {
            $current  = clone $start;
            $combiSet = [];
            while ($current <= $end) {
                $period     = $current->format('Ymd');
                $periodName = app('navigation')->periodShow($current, $format);
                $currentEnd = app('navigation')->endOfPeriod($current, $format);
                /**
                 * @var string     $name
                 * @var Collection $combi
                 */
                $spent             = $this->spentInPeriod($accounts, $combi, $current, $currentEnd);
                $earned            = $this->earnedInPeriod($accounts, $combi, $current, $currentEnd);
                $current           = app('navigation')->addPeriod($current, $format, 0);
                $combiSet[$period] = [
                    'period' => $periodName,
                    'spent'  => $spent,
                    'earned' => $earned,
                ];
            }
            $result[$name] = $combiSet;
        }
        $result = view('reports.partials.exp-grouped', compact('result'))->render();
        $cache->store($result);

        return $result;
    }

    protected function combineAccounts(Collection $accounts): array
    {
        $combined = [];
        /** @var Account $expenseAccount */
        foreach ($accounts as $expenseAccount) {
            $collection = new Collection;
            $collection->push($expenseAccount);

            $revenue = $this->accountRepository->findByName($expenseAccount->name, [AccountType::REVENUE]);
            if (!is_null($revenue->id)) {
                $collection->push($revenue);
            }
            $combined[$expenseAccount->name] = $collection;
        }

        return $combined;
    }

    /**
     * @param Collection $assets
     * @param Collection $opposing
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     */
    protected function earnedByCategory(Collection $assets, Collection $opposing, Carbon $start, Carbon $end): array
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setRange($start, $end)->setTypes([TransactionType::DEPOSIT])->setAccounts($assets);
        $collector->setOpposingAccounts($opposing)->withCategoryInformation();
        $set = $collector->getJournals();
        $sum = [];
        // loop to support multi currency
        foreach ($set as $transaction) {
            $currencyId   = $transaction->transaction_currency_id;
            $categoryName = $transaction->transaction_category_name;
            $categoryId   = intval($transaction->transaction_category_id);
            // if null, grab from journal:
            if ($categoryId === 0) {
                $categoryName = $transaction->transaction_journal_category_name;
                $categoryId   = intval($transaction->transaction_journal_category_id);
            }
            if ($categoryId !== 0) {
                $categoryName = app('steam')->tryDecrypt($categoryName);
            }

            // if not set, set to zero:
            if (!isset($sum[$categoryId][$currencyId])) {
                $sum[$categoryId][$currencyId] = [
                    'sum'      => '0',
                    'category' => [
                        'id'   => $categoryId,
                        'name' => $categoryName,
                    ],
                    'currency' => [
                        'symbol' => $transaction->transaction_currency_symbol,
                        'dp'     => $transaction->transaction_currency_dp,
                    ],
                ];
            }

            // add amount
            $sum[$categoryId][$currencyId]['sum'] = bcadd($sum[$categoryId][$currencyId]['sum'], $transaction->transaction_amount);
        }

        return $sum;
    }

    protected function earnedInPeriod(Collection $assets, Collection $opposing, Carbon $start, Carbon $end): array
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setRange($start, $end)->setTypes([TransactionType::DEPOSIT])->setAccounts($assets);
        $collector->setOpposingAccounts($opposing);
        $set = $collector->getJournals();
        $sum = [];
        // loop to support multi currency
        foreach ($set as $transaction) {
            $currencyId = $transaction->transaction_currency_id;

            // if not set, set to zero:
            if (!isset($sum[$currencyId])) {
                $sum[$currencyId] = [
                    'sum'      => '0',
                    'currency' => [
                        'symbol' => $transaction->transaction_currency_symbol,
                        'dp'     => $transaction->transaction_currency_dp,
                    ],
                ];
            }

            // add amount
            $sum[$currencyId]['sum'] = bcadd($sum[$currencyId]['sum'], $transaction->transaction_amount);
        }

        return $sum;
    }

    /**
     * @param Collection $assets
     * @param Collection $opposing
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return array
     */
    protected function spentByBudget(Collection $assets, Collection $opposing, Carbon $start, Carbon $end): array
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])->setAccounts($assets);
        $collector->setOpposingAccounts($opposing)->withBudgetInformation();
        $set = $collector->getJournals();
        $sum = [];
        // loop to support multi currency
        foreach ($set as $transaction) {
            $currencyId = $transaction->transaction_currency_id;
            $budgetName = $transaction->transaction_budget_name;
            $budgetId   = intval($transaction->transaction_budget_id);
            // if null, grab from journal:
            if ($budgetId === 0) {
                $budgetName = $transaction->transaction_journal_budget_name;
                $budgetId   = intval($transaction->transaction_journal_budget_id);
            }
            if ($budgetId !== 0) {
                $budgetName = app('steam')->tryDecrypt($budgetName);
            }

            // if not set, set to zero:
            if (!isset($sum[$budgetId][$currencyId])) {
                $sum[$budgetId][$currencyId] = [
                    'sum'      => '0',
                    'budget'   => [
                        'id'   => $budgetId,
                        'name' => $budgetName,
                    ],
                    'currency' => [
                        'symbol' => $transaction->transaction_currency_symbol,
                        'dp'     => $transaction->transaction_currency_dp,
                    ],
                ];
            }

            // add amount
            $sum[$budgetId][$currencyId]['sum'] = bcadd($sum[$budgetId][$currencyId]['sum'], $transaction->transaction_amount);
        }

        return $sum;
    }

    /**
     * @param Collection $assets
     * @param Collection $opposing
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     */
    protected function spentByCategory(Collection $assets, Collection $opposing, Carbon $start, Carbon $end): array
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])->setAccounts($assets);
        $collector->setOpposingAccounts($opposing)->withCategoryInformation();
        $set = $collector->getJournals();
        $sum = [];
        // loop to support multi currency
        foreach ($set as $transaction) {
            $currencyId   = $transaction->transaction_currency_id;
            $categoryName = $transaction->transaction_category_name;
            $categoryId   = intval($transaction->transaction_category_id);
            // if null, grab from journal:
            if ($categoryId === 0) {
                $categoryName = $transaction->transaction_journal_category_name;
                $categoryId   = intval($transaction->transaction_journal_category_id);
            }
            if ($categoryId !== 0) {
                $categoryName = app('steam')->tryDecrypt($categoryName);
            }

            // if not set, set to zero:
            if (!isset($sum[$categoryId][$currencyId])) {
                $sum[$categoryId][$currencyId] = [
                    'sum'      => '0',
                    'category' => [
                        'id'   => $categoryId,
                        'name' => $categoryName,
                    ],
                    'currency' => [
                        'symbol' => $transaction->transaction_currency_symbol,
                        'dp'     => $transaction->transaction_currency_dp,
                    ],
                ];
            }

            // add amount
            $sum[$categoryId][$currencyId]['sum'] = bcadd($sum[$categoryId][$currencyId]['sum'], $transaction->transaction_amount);
        }

        return $sum;
    }

    /**
     * @param Collection $assets
     * @param Collection $opposing
     * @param Carbon     $start
     * @param Carbon     $end
     *
     * @return string
     */
    protected function spentInPeriod(Collection $assets, Collection $opposing, Carbon $start, Carbon $end): array
    {
        /** @var JournalCollectorInterface $collector */
        $collector = app(JournalCollectorInterface::class);
        $collector->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])->setAccounts($assets);
        $collector->setOpposingAccounts($opposing);
        $set = $collector->getJournals();
        $sum = [];
        // loop to support multi currency
        foreach ($set as $transaction) {
            $currencyId = $transaction->transaction_currency_id;

            // if not set, set to zero:
            if (!isset($sum[$currencyId])) {
                $sum[$currencyId] = [
                    'sum'      => '0',
                    'currency' => [
                        'symbol' => $transaction->transaction_currency_symbol,
                        'dp'     => $transaction->transaction_currency_dp,
                    ],
                ];
            }

            // add amount
            $sum[$currencyId]['sum'] = bcadd($sum[$currencyId]['sum'], $transaction->transaction_amount);
        }

        return $sum;
    }

}