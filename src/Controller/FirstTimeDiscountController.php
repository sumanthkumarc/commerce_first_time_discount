<?php

namespace Drupal\commerce_first_time_discount\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class FirstTimeDiscountController.
 */
class FirstTimeDiscountController extends ControllerBase {

  /**
   * Provides the interface for managing the First time discounts.
   *
   * @return array
   *   Return content for managing discounts.
   */
  public function renderUserInterface() {
    $content = [];

    $content['form'] = $this->formBuilder()
      ->getForm('Drupal\commerce_first_time_discount\Form\DiscountControlForm');

    return $content;
  }

}
