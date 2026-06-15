<?php

namespace App\Support;

final class OrganizationPaymentMethods
{
  public const PAYMONGO_KEYS = [
    'qrph',
    'card',
    'gcash',
    'grab_pay',
    'shopee_pay',
    'billease',
    'paymaya',
    'dob',
    'dob_fixed_minimum',
    'brankas',
  ];

  public const PAYPAL_KEYS = [
    'paypal',
  ];

  public static function providerForName(string $name): string
  {
    return in_array($name, self::PAYPAL_KEYS, true) ? 'paypal' : 'paymongo';
  }

  /**
   * @return list<string>
   */
  public static function allKeys(): array
  {
    return array_merge(self::PAYMONGO_KEYS, self::PAYPAL_KEYS);
  }

  /**
   * @return list<array{name: string, value: bool, provider: string}>
   */
  public static function defaults(): array
  {
    return array_map(
      static function (string $name) {
        return [
          'name' => $name,
          'value' => false,
          'provider' => self::providerForName($name),
        ];
      },
      self::allKeys(),
    );
  }

  /**
   * @param array<int, array<string, mixed>>|null $stored
   * @return list<array{name: string, value: bool, provider: string}>
   */
  public static function normalize(?array $stored): array
  {
    $indexed = collect(self::defaults())->keyBy('name');

    if (is_array($stored)) {
      foreach ($stored as $item) {
        if (! is_array($item)) {
          continue;
        }
        $name = isset($item['name']) ? (string) $item['name'] : '';
        if ($name === '' || ! $indexed->has($name)) {
          continue;
        }
        $indexed[$name] = [
          'name' => $name,
          'value' => (bool) ($item['value'] ?? false),
          'provider' => self::providerForName($name),
        ];
      }
    }

    return $indexed->values()->all();
  }

  /**
   * Default PayMongo checkout_sessions payment_method_types (legacy when organizer has not configured methods).
   *
   * @return list<string>
   */
  public static function defaultPaymongoCheckoutApiTypes(): array
  {
    return [
      'shopee_pay',
      'qrph',
      'billease',
      'card',
      'dob',
      'dob_ubp',
      'brankas_bdo',
      'brankas_landbank',
      'brankas_metrobank',
      'gcash',
      'grab_pay',
      'paymaya',
    ];
  }

  /**
   * Map an organization payment_methods "name" to PayMongo API payment_method_types.
   *
   * @return list<string>
   */
  public static function paymongoApiTypesForOrganizationKey(string $name): array
  {
    return match ($name) {
      'brankas' => ['brankas_bdo', 'brankas_landbank', 'brankas_metrobank'],
      'dob', 'dob_fixed_minimum' => ['dob', 'dob_ubp'],
      'qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay', 'billease', 'paymaya' => [$name],
      default => [],
    };
  }

  /**
   * Enabled PayMongo API types from normalized organizer settings.
   *
   * @param list<array{name: string, value: bool, provider?: string}> $normalized
   * @return list<string>
   */
  public static function enabledPaymongoCheckoutApiTypesFromNormalized(array $normalized): array
  {
    $types = [];
    foreach ($normalized as $item) {
      if (! ($item['value'] ?? false)) {
        continue;
      }
      $name = $item['name'] ?? '';
      if (! in_array($name, self::PAYMONGO_KEYS, true)) {
        continue;
      }
      foreach (self::paymongoApiTypesForOrganizationKey($name) as $t) {
        $types[$t] = true;
      }
    }

    return array_keys($types);
  }

  /**
   * @param list<array{name: string, value: bool, provider?: string}> $normalized
   */
  public static function isPaypalEnabledFromNormalized(array $normalized): bool
  {
    foreach ($normalized as $item) {
      if (($item['name'] ?? '') === 'paypal' && ($item['value'] ?? false)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Raw JSON from organizations.payment_methods. Null means unset — use full legacy PayMongo list and allow PayPal.
   *
   * @param array<int, array<string, mixed>>|null $storedFromDb
   * @return list<string>
   */
  public static function checkoutPaymongoApiTypes(?array $storedFromDb): array
  {
    if ($storedFromDb === null) {
      return self::defaultPaymongoCheckoutApiTypes();
    }

    return self::enabledPaymongoCheckoutApiTypesFromNormalized(self::normalize($storedFromDb));
  }

  /**
   * @param array<int, array<string, mixed>>|null $storedFromDb
   */
  public static function checkoutPaypalAllowed(?array $storedFromDb): bool
  {
    if ($storedFromDb === null) {
      return true;
    }

    return self::isPaypalEnabledFromNormalized(self::normalize($storedFromDb));
  }
}
