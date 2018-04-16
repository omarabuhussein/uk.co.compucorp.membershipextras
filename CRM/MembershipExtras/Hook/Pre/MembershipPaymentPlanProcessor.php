<?php

class CRM_MembershipExtras_Hook_Pre_MembershipPaymentPlanProcessor {

  private $params;

  private $installmentsCount;

  private $installmentsFrequency;

  private $installmentsFrequencyUnit;

  private $recurringContribution;

  public function __construct(&$params) {
    $this->params = &$params;

    $this->installmentsCount = CRM_Utils_Request::retrieve('installments', 'Int');
    $this->installmentsFrequency = CRM_Utils_Request::retrieve('installments_frequency', 'Int');
    $this->installmentsFrequencyUnit = CRM_Utils_Request::retrieve('installments_frequency_unit', 'String');
  }
  public function process() {
    if (!$this->isPaymentPlanPayment()) {
      return;
    }

    $this->createRecurringContribution();
    $this->updateContributionData();
    $this->updateLineItemData();
  }

  private function isPaymentPlanPayment() {
    $isSavingContribution = CRM_Utils_Request::retrieve('record_contribution', 'Int');
    $contributionIsPaymentPlan = CRM_Utils_Request::retrieve('contribution_type_toggle', 'String') === 'payment_plan';

    if ($isSavingContribution && $contributionIsPaymentPlan && $this->installmentsCount > 1) {
      return TRUE;
    }

    return FALSE;
  }

  private function createRecurringContribution() {
    $amountPerInstallment = $this->calculateSingleInstallmentAmount();

    $PaymentInstrument = civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'name',
      'option_group_id' => 'payment_instrument',
      'value' => $this->params['payment_instrument_id'],
    ]);

    $financialType = civicrm_api3('FinancialType', 'getvalue', [
      'return' => 'name',
      'id' => $this->params['financial_type_id'],
    ]);

    $contributionRecurParams = [
      'sequential' => 1,
      'contact_id' => $this->params['contact_id'],
      'amount' => $amountPerInstallment,
      'currency' => $this->params['currency'],
      'frequency_unit' => $this->installmentsFrequencyUnit,
      'frequency_interval' => $this->installmentsFrequency,
      'installments' => $this->installmentsCount,
      'start_date' => $this->params['receive_date'],
      'contribution_status_id' => 'In Progress',
      'is_test' => $this->params['is_test'],
      'cycle_day' => $this->calculateCycleDay(),
      'payment_processor_id' => $this->params['payment_processor_id'],
      'financial_type_id' =>  $financialType,
      'payment_instrument_id' => $PaymentInstrument,
      'campaign_id' => $this->params['campaign_id'],
    ];

    $this->recurringContribution = civicrm_api3('ContributionRecur', 'create', $contributionRecurParams)['values'][0];
  }

  private function calculateSingleInstallmentAmount() {
    return floor(($this->params['total_amount'] / $this->installmentsCount) * 100) / 100;
  }

  private function calculateCycleDay() {;
    $recurContStartDate = new DateTime($this->params['receive_date']);

    switch ($this->installmentsFrequencyUnit) {
      case 'week':
        $cycleDay =  $recurContStartDate->format('N');
        break;
      case 'month':
        $cycleDay =  $recurContStartDate->format('j');
        break;
      case 'year':
        $cycleDay =  $recurContStartDate->format('z');
        break;
      default:
        $cycleDay = 1;
    }

    return $cycleDay;
  }

  private function updateContributionData() {
    $this->params['contribution_recur_id'] =  $this->recurringContribution['id'];
    $this->params['total_amount'] =  $this->recurringContribution['amount'];
    $this->params['net_amount'] =  $this->recurringContribution['amount'];
    $this->params['tax_amount'] = $this->calculateSingleInstallmentTaxAmount();
  }

  private function calculateSingleInstallmentTaxAmount() {
    return floor(($this->params['tax_amount'] / $this->installmentsCount) * 100) / 100;
  }

  private function updateLineItemData() {
    $membershipTypeID =  key($this->params['line_item']);
    $priceValueID = key(current($this->params['line_item']));

    $lineItemAmount = $this->recurringContribution['amount'] - $this->params['tax_amount'];
    $this->params['line_item'][$membershipTypeID][$priceValueID]['line_total'] = $lineItemAmount;
    $this->params['line_item'][$membershipTypeID][$priceValueID]['unit_price'] = $lineItemAmount;
    $this->params['line_item'][$membershipTypeID][$priceValueID]['tax_amount'] = $this->params['tax_amount'];
  }

}
