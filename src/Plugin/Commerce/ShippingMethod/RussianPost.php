<?php

namespace Drupal\cs_rp\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\physical\WeightUnit;
use LapayGroup\RussianPost\Exceptions\RussianPostException;
use LapayGroup\RussianPost\TariffCalculation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the RussianPost shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "russian_post",
 *   label = @Translation("Russian post"),
 * )
 */
class RussianPost extends ShippingMethodBase {

  /**
   * The RussianPostManager.
   *
   * @var \Drupal\cs_rp\RussianPostManager
   */
  protected $russianPostManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->russianPostManager = $container->get('russian_post_manager');
    $instance->configuration['services'] = [];
    foreach ($configuration['dynamic_services'] ?? [] as $id => $item) {
      $data = $instance->getServiceDataById($id);
      if (empty($data)) {
        $instance
          ->messenger()
          ->addError($instance->t('Not found :service', [':service' => $id]));
        continue;
      }
      $instance->configuration['services'][] = $id;
      $instance->services[$id] = new ShippingService($id, $data['name']);
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'dynamic_services' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['services']['#access'] = TRUE;
    $form['services']['#disabled'] = TRUE;
    foreach ($this->configuration['dynamic_services'] ?? [] as $service) {
      $data = $this->getServiceDataById($service['id']);
      if (empty($data)) {
        continue;
      }
      $additionalServices = [];
      foreach ($service['additional_services'] ?? [] as $additionalService) {
        foreach ($data['service_list'] ?? [] as $item) {
          if ($additionalService == $item['id']) {
            $additionalServices[] = $item['name'];
          }
        }
      }
      $description = $this->t('Additional services not selected.');
      if (!empty($additionalServices)) {
        $description = $this->t('Additional services: :services', [
          ':services' => implode(', ', $additionalServices),
        ]);
      }
      $form['services'][$service['id']]['#description'] = $description;
    }
    $form['dynamic_services'] = [
      '#type' => 'hidden',
      '#default_value' => Json::encode($this->configuration['dynamic_services'] ?? []),
    ];
    $form['default_pack'] = [
      '#type' => 'select',
      '#title' => $this->t('Default package'),
      '#options' => [],
    ];
    $form['edit_services'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit services'),
      '#attributes' => [
        'class' => ['button use-ajax'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 1200,
        ]),
      ],
      '#url' => Url::fromRoute('cs_rp.add_service', [
        'commerce_shipping_method' => $form_state->getFormObject()->getEntity()->id(),
      ]),
    ];
    // 27020, 28020, 47020, 29020

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['dynamic_services'] = Json::decode($values['dynamic_services']);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {

    $shippingProfile = $shipment->getShippingProfile();
    if (!$shippingProfile->hasField('address')) {
      return [];
    }
    if (!$shipment->hasItems()) {
      return [];
    }
    $declaredPrice = $shipment->getTotalDeclaredValue();
    $weight = $shipment->getWeight()->convert(WeightUnit::GRAM)->getNumber();
    $rates = [];
    foreach ($this->configuration['dynamic_services'] as $service) {
      $params = [
        'weight' => 20,
        'sumoc' => $declaredPrice->getNumber(),
        'from' => 109012,
        'to' => 664000,
        'pack' => 10,
      ];
      try {
        $TariffCalculation = new TariffCalculation();
        $calcInfo = $TariffCalculation->calculate($service['id'], $params, $service['additional_services']);
        $rates[] = new ShippingRate([
          'shipping_method_id' => $this->parentEntity->id(),
          'service' => $this->services[$service['id']],
          //'description' => $this->services[$service['id']],
          'amount' => Price::fromArray([
            'number' => $calcInfo->getPayNds(),
            'currency_code' => $declaredPrice->getCurrencyCode(),
          ]),
        ]);
      }
      catch (RussianPostException $e) {
        $this->messenger()->addError($e->getMessage());
      }

    }

    return $rates;
  }

  /**
   * Returns service data by id.
   *
   * @param int $id
   *   The service id.
   *
   * @return array
   *   The service data.
   */
  protected function getServiceDataById(int $id): array {
    foreach ($this->russianPostManager->getCategoryList() as $category) {
      foreach ($category["subcategory_list"] ?? [] as $subCategory) {
        foreach ($subCategory['items'] ?? [] as $service) {
          if ($service['id'] == $id) {
            return $service + [
              'category' => $category['category'],
              'sub_category_name' => $subCategory['subcategory'],
              'sub_category_description' => $subCategory['description'],
            ];
          }
        }
      }
    }
    return [];
  }

}
