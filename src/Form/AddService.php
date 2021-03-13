<?php

namespace Drupal\cs_rp\Form;

use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\cs_rp\Plugin\Commerce\ShippingMethod\RussianPost;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Commerce shipping Russian Post form.
 */
class AddService extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'cs_rp_add_service';
  }

  /**
   * The RussianPostManager.
   *
   * @var \Drupal\cs_rp\RussianPostManager
   */
  protected $russianPostManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->russianPostManager = $container->get('russian_post_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $commerce_shipping_method = NULL) {
    if (!$commerce_shipping_method->getPlugin() instanceof RussianPost) {
      $form['error'] = [
        '#theme' => 'status_messages',
        '#message_list' => ['error' => [$this->t('This shipping method not support Russian Post.')]],
      ];
      return $form;
    }
    $form_state->set('commerce_shipping_method', $commerce_shipping_method);
    $config = $commerce_shipping_method->getPlugin()->getConfiguration();
    $form['services'] = [
      '#type' => 'vertical_tabs',
    ];
    foreach ($this->russianPostManager->getCategoryList() as $category) {
      $form['services'][$category['id']] = [
        '#type' => 'details',
        '#title' => $category['category'],
        '#group' => 'services',
      ];
      foreach ($category["subcategory_list"] ?? [] as $subCategory) {
        $form['services'][$category['id']][$subCategory['id']] = [
          '#type' => 'details',
          '#title' => $subCategory['subcategory'],
          '#description' => $subCategory['description'],
        ];
        foreach ($subCategory['items'] ?? [] as $service) {
          $container = [
            '#type' => 'container',
            '#tree' => TRUE,
            '#parents' => ['services', $service['id']],
          ];
          $container['status'] = [
            '#type' => 'checkbox',
            '#title' => $service['name'],
            '#default_value' => isset($config['dynamic_services'][$service['id']]),
          ];
          if ($container['status']['#default_value']) {
            $form['services'][$category['id']][$subCategory['id']]['#open'] = TRUE;
          }
          $add_service_list = [];
          foreach ($service['service_list'] ?? [] as $serviceItem) {
            $add_service_list[$serviceItem['id']] = $serviceItem['name'];
          }

          if (!empty($add_service_list)) {
            $container['add_service_list'] = [
              '#type' => 'checkboxes',
              '#title' => $this->t('Additional services'),
              '#options' => $add_service_list,
              '#defaul_value' => $config['dynamic_services'][$service['id']]['additional_services'] ?? [],
              '#states' => [
                'visible' => [
                  ':input[name="services[' . $service['id'] . '][status]"]' => ['checked' => TRUE],
                ],
              ],
            ];
          }
          $form['services'][$category['id']][$subCategory['id']][$service['id']] = $container;
        }
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $shippingMethod = $form_state->get('commerce_shipping_method');
    if (!$shippingMethod instanceof ShippingMethod) {
      $form_state->setError($form, $this->t('Not defined shipping method.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $services = [];
    foreach ($form_state->getValue('services', []) as $id => $service) {
      if (empty($service['status'])) {
        continue;
      }
      $services[$id] = [
        'id' => $id,
        'additional_services' => isset($service['add_service_list']) ? array_filter($service['add_service_list']) : [],
      ];
    }
    /** @var \Drupal\commerce_shipping\Entity\ShippingMethod $shippingMethod */
    $shippingMethod = $form_state->get('commerce_shipping_method');
    $config = $shippingMethod->getPlugin()->getConfiguration();
    $config['dynamic_services'] = $services;
    $shippingMethod->set('plugin', [
      'target_plugin_id' => $shippingMethod->getPlugin()->getPluginId(),
      'target_plugin_configuration' => $config,
    ]);
    $shippingMethod->save();
    $form_state->setRedirectUrl($shippingMethod->toUrl('edit-form'));
  }

}
