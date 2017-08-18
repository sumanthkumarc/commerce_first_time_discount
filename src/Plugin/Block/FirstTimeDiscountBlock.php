<?php

namespace Drupal\commerce_first_time_discount\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\commerce_promotion\Entity\Coupon;

/**
 * Provides a 'FirstTimeDiscountBlock' block.
 *
 * @Block(
 *  id = "first_time_discount_block",
 *  admin_label = @Translation("First time discount block"),
 * )
 */
class FirstTimeDiscountBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];

    $config = \Drupal::config('commerce_first_time_discount.discountcontrol');

    if (!$config->get('ftd_status')) {
      return [];
    }

    $coupon_id = \Drupal::state()->get('commerce_ftd_active_coupon');
    $coupon = Coupon::load($coupon_id);

    $discount = $coupon->getPromotion()->getOffer()->getPercentage();

    $build['first_time_discount_block'] = [
      '#theme' => 'commerce_first_time_discount_block',
      '#content' => '',
      '#percentage' => $discount * 100,
      '#coupon_code' => $coupon->getCode(),
    ];

    return $build;
  }

}
