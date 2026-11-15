<?php

namespace App\Domains\Finance\Exceptions;

use LogicException;

/**
 * A ledger transaction whose debits and credits differ, mixes currencies or
 * has fewer than two legs. Always a programming error — nothing is written.
 */
class UnbalancedPosting extends LogicException {}
