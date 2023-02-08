<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Stripe\Event;

use Civi\API\Event\AuthorizedTrait;

/**
 * Class AuthorizeEvent
 *
 * @package Civi\API\Event
 *
 * Determine whether the API request is allowed for the current user.
 * For successful execution, at least one listener must invoke
 * $event->authorize().
 *
 * Event name: 'civi.api.authorize'
 */
class AuthorizeEvent extends \Civi\Core\Event\GenericHookEvent {

  use AuthorizedTrait;

  /**
   * @var string
   */
  protected $entityName;

  /**
   * @var string
   */
  protected $actionName;

  /**
   * @var array
   */
  protected $params = [];

  /**
   * @var array
   */
  protected $reasonDescriptions = [];

  public function __construct($entityName, $actionName, $params) {
    $this->entityName = $entityName;
    $this->actionName = $actionName;
    $this->params = $params;
  }

  /**
   * @return string
   */
  public function getEntityName(): string {
    return $this->entityName;
  }

  /**
   * @return string
   */
  public function getActionName(): string {
    return $this->actionName;
  }

  /**
   * @return array
   */
  public function getParams(): array {
    return $this->params;
  }

  /**
   * @return array
   */
  public function getReasonDescriptions(): array {
    return $this->reasonDescriptions;
  }

  /**
   * @param string $nameOfCheck
   * @param string $reason
   *
   * @return void
   */
  public function setReasonDescription(string $nameOfCheck, string $reason) {
    $this->reasonDescriptions[$nameOfCheck] = $reason;
  }



}
