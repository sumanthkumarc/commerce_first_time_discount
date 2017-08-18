<?php

namespace Drupal\commerce_first_time_discount\Form;

use CommerceGuys\Intl\Calculator;
use Drupal\commerce_promotion\Entity\Coupon;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\Core\Link;

/**
 * Class DiscountControlForm.
 */
class DiscountControlForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_first_time_discount.discountcontrol',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'discount_control_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_first_time_discount.discountcontrol');
    $form = parent::buildForm($form, $form_state);

    /*if ($config->get('ftd_status')) {
      $form['block_content'] = [
        '#type' => 'text_format',
        '#format' => 'basic_html',
        '#title' => $this->t('Content to be shown in sidebar promotion block'),
        '#default_value' => $config->get('block_content'),
      ];
    }*/

    $form['status']['ftd_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('First Time Discount'),
      '#default_value' => $config->get('ftd_status'),
      '#options' => [1 => $this->t('Enable'), 0 => $this->t('Disable')],
    ];

    if ($config->get('ftd_status')) {
      $form['generate'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['generate-form'],
        ],
      ];

      $form['generate']['coupon_discount_percent'] = [
        '#type' => 'number',
        '#title' => $this->t('New Coupon Discount %'),
        '#min' => 1,
        '#max' => 100,
        '#step' => 1,
        '#default_value' => 1,
      ];

      $form['generate']['coupon_submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate Coupon'),
        '#name' => 'generate_coupon',
      ];

      $coupon_id = \Drupal::state()->get('commerce_ftd_active_coupon');
      $coupon = Coupon::load($coupon_id);

      $discount = $coupon->getPromotion()->getOffer()->getPercentage();

      $form['active_coupon'] = [
        '#markup' => 'Only one coupon active at any given time. Currenly active Coupon code: ' . $coupon->getCode() . ', Discount: ' . $discount * 100 . '%',
      ];

      $form['coupons_table'] = $this->getCouponsTable();

      $form['pager'] = [
        '#type' => 'pager',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('commerce_first_time_discount.discountcontrol');
    $values = $form_state->getValues();
    $trig_el = $form_state->getTriggeringElement();

    $status = $values['ftd_status'];
    $discount_percentage = (string) $values['coupon_discount_percent'];
    /*$block_content = $values['block_content'];*/
    $promotion = $this->getFirstTimePromotion();

    if ($trig_el['#name'] == 'generate_coupon') {
      $promotion = $this->getFirstTimePromotion();
      $this->setDiscount($promotion, $discount_percentage);
      $this->disableAllCoupons($promotion);

      // @Todo Coupon code should be unique. Need to do $coupon->validate() or
      // $promotion->validate() before saving.
      $coupon_code = $this->getCouponCode();
      $new_coupon = $this->createCoupon($promotion, $coupon_code);
      $promotion->addCoupon($new_coupon);
      $promotion->setEnabled(TRUE);

      \Drupal::state()->set('commerce_ftd_active_coupon', $new_coupon->id());
    }

    if (!$status) {
      $promotion->setEnabled(FALSE);
    }
    else {
      $promotion->setEnabled(TRUE);
    }

    $promotion->save();
    $config->set('ftd_status', $status)->save();
    //$config->set('block_content', $block_content)->save();
  }

  /**
   * Disables all the coupons for given promotion.
   *
   * @param \Drupal\commerce_promotion\Entity\Promotion $promotion
   *   The first time promotion.
   *
   * @return \Drupal\commerce_promotion\Entity\Promotion
   *   The promotion object with coupons disabled.
   */
  public function disableAllCoupons(Promotion $promotion): Promotion {
    if ($promotion->hasCoupons()) {
      $coupons = $promotion->getCoupons();

      foreach ($coupons as $coupon) {
        /* @var Coupon $coupon */
        $coupon->setEnabled(FALSE);
        $coupon->save();
      }

      $promotion->setCoupons($coupons);
    }

    return $promotion;
  }

  /**
   * Returns if the first time promotion is available yet.
   *
   * @return bool
   *   TRUE if exists else FALSE.
   */
  public function isFirstTimePromotionAvailable(): bool {
    $state = \Drupal::state();
    $first_time_promotion = $state->get('first_time_promotion');

    if (!$first_time_promotion || empty(Promotion::load($first_time_promotion))) {
      return FALSE;
    }
    else {
      return TRUE;
    }
  }

  /**
   * Sets the first time promotion id in state.
   *
   * @param \Drupal\commerce_promotion\Entity\Promotion $promotion
   *   The first time promotion object.
   */
  public function setFirstTimePromotion(Promotion $promotion) {
    $state = \Drupal::state();
    $state->set('first_time_promotion', $promotion->id());
  }

  /**
   * Creates and returns the first time promotion object, if it doesn't exist yet.
   *
   * @return \Drupal\commerce_promotion\Entity\Promotion
   *   The first time promotion object.
   */
  public function getFirstTimePromotion(): Promotion {
    $state = \Drupal::state();

    if (!$this->isFirstTimePromotionAvailable()) {
      $order_types = \Drupal::entityTypeManager()
        ->getStorage('commerce_order_type')
        ->loadMultiple();

      $order_types = array_keys($order_types);
      // @Todo get all store ids.
      $store_ids = [1];

      $promotion = $this->createFirstTimePromotion($store_ids, $order_types);
      $this->setFirstTimePromotion($promotion);
    }
    else {
      $promotion = Promotion::load($state->get('first_time_promotion'));
    }

    return $promotion;
  }

  /**
   * Sets the discount for the given promotion.
   *
   * @param \Drupal\commerce_promotion\Entity\Promotion $promotion
   *   The first time promotion object.
   * @param string $discount_percentage
   *   The discount percentage.
   *
   * @return \Drupal\commerce_promotion\Entity\Promotion
   *   The first time promotion object.
   */
  public function setDiscount(Promotion $promotion, string $discount_percentage): Promotion {
    if ($discount_percentage !== 0) {
      $discount_amount = Calculator::divide($discount_percentage, 100, 2);
    }
    else {
      $discount_amount = 0;
    }

    $offer['target_plugin_id'] = 'order_percentage_off';
    $offer['target_plugin_configuration'] = ['percentage' => $discount_amount];

    $promotion->set('offer', $offer);

    return $promotion;
  }

  /**
   * Creates first time promotion.
   *
   * @param array $store_ids
   *   The store ids for this promotion to apply to.
   * @param array $order_types
   *   Order types this promotion to be applied.
   *
   * @return \Drupal\commerce_promotion\Entity\Promotion|static
   *   The newly created promotion object.
   */
  public function createFirstTimePromotion(array $store_ids, array $order_types): Promotion {

    $promotion = Promotion::create([
      'name' => 'first_time_promotion',
      'order_types' => $order_types,
      'stores' => $store_ids,
      'status' => TRUE,
      'offer' => [
        'target_plugin_id' => 'order_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '',
        ],
      ],
      'conditions' => [
        [
          'target_plugin_id' => 'ftd_orders_per_user',
          'target_plugin_configuration' => [
            'operator' => '==',
            'orders' => 1,
          ],
        ],
      ],
    ]);
    $promotion->save();

    return $promotion;
  }

  /**
   * Returns the Coupon code in format : FTD0001 etc.
   *
   * @return string
   *   The coupon code.
   */
  public function getCouponCode(): string {
    $promotion = $this->getFirstTimePromotion();
    $count = count($promotion->getCouponIds());
    return 'FTD' . str_pad($count, 4, '0', STR_PAD_LEFT);
  }

  /**
   * Creates the coupon.
   *
   * @param \Drupal\commerce_promotion\Entity\PromotionInterface $promotion
   *   The promotion object to attach the coupon to.
   * @param string $coupon_code
   *   The coupon code for this coupon.
   *
   * @return Coupon|static
   *   Returns the Coupon object created.
   */
  public function createCoupon(PromotionInterface $promotion, string $coupon_code): Coupon {
    $coupon = Coupon::create([
      'promotion_id' => $promotion->id(),
      'code' => $coupon_code,
    ]);

    $coupon->save();
    return $coupon;
  }

  /**
   * Returns the coupons table.
   *
   * @return array
   *   Render array for coupons table.
   */
  public function getCouponsTable(): array {

    $form['promotions'] = [
      '#type' => 'table',
      '#header' => $this->buildHeader(),
      '#empty' => $this->t('There are no coupons yet.'),
    ];

    $usages = $this->getCouponsUsage();
    foreach ($usages as $coupon_id => $usage) {
      $row = $this->buildRow($usage);

      $row['coupon'] = ['#markup' => $row['coupon']];
      $row['status'] = ['#markup' => $row['status']];
      $row['applied_by'] = ['#markup' => $row['applied_by']];
      $row['product'] = ['#markup' => $row['product']];

      $form['promotions'][] = $row;
    }

    return $form;
  }

  /**
   * Returns the header for table.
   *
   * @return array
   *   Header array.
   */
  public function buildHeader(): array {
    $header['coupon'] = $this->t('Coupon');
    $header['status'] = $this->t('Status');
    $header['applied_by'] = $this->t('Applied by');
    $header['product'] = $this->t('Product');

    return $header;
  }

  /**
   * Builds a coupon usage row.
   *
   * @param array $usage
   *   Coupon usage array with details.
   *
   * @return array
   *   Render array for the row.
   */
  public function buildRow(array $usage): array {
    $coupon = Coupon::load($usage['coupon_id']);

    $row['coupon'] = $coupon->label();

    if ($coupon->isEnabled()) {
      if (!empty($usage['order_id'])) {
        $status = 'Applied and Active';
      }
      else {
        $status = 'Unused and Active';
      }
    }
    else {
      if (!empty($usage['order_id'])) {
        $status = 'Applied and Inactive';
      }
      else {
        $status = 'Unused and Inactive';
      }
    }

    if (!empty($usage['order_id'])) {
      $order = Order::load($usage['order_id']);
      $items = $order->getItems();
      $products = [];
      foreach ($items as $item) {
        $product = $item->getPurchasedEntity();
        /* @var OrderItem $item */
        $label = $product->label();
        $route_params = ['commerce_product' => $product->id()];
        $route = 'entity.commerce_product.canonical';
        $products[] = Link::createFromRoute($label, $route, $route_params)
          ->toString();
      }

      $label = $order->getCustomer()->getDisplayName();
      $route_params = ['user' => $order->getCustomer()->id()];
      $route = 'entity.user.canonical';
      $user = Link::createFromRoute($label, $route, $route_params)
        ->toString();

      $products = implode(',', $products);
    }
    else {
      $products = $user = $this->t('--');
    }

    $row['status'] = $status;
    $row['applied_by'] = $user;
    $row['product'] = $products;

    return $row;
  }

  /**
   * Returns the coupons usage details.
   *
   * @return array
   *   Coupon usage details array.
   */
  public function getCouponsUsage(): array {
    $promotion_ids = \Drupal::state()->get('first_time_promotion');

    $query = \Drupal::database()->select('commerce_promotion_coupon', 'cpc');
    $query->addField('cpc', 'id', 'coupon_id');
    $query->fields('cpu', ['order_id']);
    $query->leftJoin('commerce_promotion_usage', 'cpu', 'cpc.id=cpu.coupon_id');
    $query->condition('cpc.promotion_id', $promotion_ids, 'IN');
    $usages = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->limit(10)
      ->execute()
      ->fetchAllAssoc('coupon_id', \PDO::FETCH_ASSOC);

    return $usages;
  }

}
