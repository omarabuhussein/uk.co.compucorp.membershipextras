<?php

class CRM_MembershipExtras_MembershipInstallmentsHandler {

  private $previousRecurContribution;

  private $currentRecurContribution;

  private $currentRecurContributionlastContribution;

  private $contributionPendingStatusValue;

  public function __construct($currentRecurContributionId, $previousRecurContributionId = NULL) {
    $this->currentRecurContribution['id'] = $currentRecurContributionId;
    $this->previousRecurContribution['id'] = $previousRecurContributionId;

    $this->setContributionPendingStatusValue();
  }

  private function setContributionPendingStatusValue() {
    $this->contributionPendingStatusValue =  civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'contribution_status',
      'name' => 'Pending',
    ]);
  }

  public function createFirstInstallmentContribution($recurContributionID, $previousRecurContribution) {
    $this->createContribution();
  }

  public function createRemainingInstalmentContributionsUpfront() {
    $this->setCurrentRecurContribution();
    $this->setCurrentRecurContributionLastContribution();

    $installmentsCount = (int) $this->currentRecurContribution['installments'];
    for($contributionNumber = 2; $contributionNumber <= $installmentsCount; $contributionNumber++) {
      $this->createContribution($contributionNumber);
    }
  }

  private function setCurrentRecurContribution() {
    $this->currentRecurContribution =  civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $this->currentRecurContribution['id'],
    ])['values'][0];
  }

  private function setCurrentRecurContributionLastContribution() {
    $contribution = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'return' => ['currency', 'source', 'non_deductible_amount',
        'contact_id', 'fee_amount', 'total_amount', 'payment_instrument_id',
        'is_test', 'campaign_id', 'tax_amount', 'contribution_recur_id', 'financial_type_id'],
      'contribution_recur_id' => $this->currentRecurContribution['id'],
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ])['values'][0];

    $contribution['membership_id'] = civicrm_api3('MembershipPayment', 'getvalue', [
      'return' => 'membership_id',
      'contribution_id' => $contribution['id'],
    ]);

    $softContribution = civicrm_api3('ContributionSoft', 'get', array(
      'sequential' => 1,
      'return' => ['contact_id', 'soft_credit_type_id'],
      'contribution_id' => $contribution['id'],
    ));

    if (!empty($softContribution['values'][0])) {
      $softContribution = $softContribution['values'][0];
      $contribution['soft_credit'] = [
        'soft_credit_type_id' => $softContribution['soft_credit_type_id'],
        'contact_id' => $softContribution['contact_id'],
      ];
    }

    $this->currentRecurContributionlastContribution = $contribution;
  }

  private function createContribution($contributionNumber = 1) {
    $params = $this->buildContributionParams($contributionNumber);
    $contribution = CRM_Member_BAO_Membership::recordMembershipContribution($params);

    $this->createLineItem($contribution);
  }

  private function buildContributionParams($contributionNumber) {
    $params =  [
      'currency' => $this->currentRecurContributionlastContribution['currency'],
      'contribution_source' => $this->currentRecurContributionlastContribution['source'],
      'non_deductible_amount' => $this->currentRecurContributionlastContribution['non_deductible_amount'],
      'contact_id' => $this->currentRecurContributionlastContribution['contact_id'],
      'fee_amount' => $this->currentRecurContributionlastContribution['fee_amount'],
      'total_amount' => $this->currentRecurContributionlastContribution['total_amount'],
      'receive_date' => $this->calculateInstallmentReceiveDate($contributionNumber), // TODO : correct
      'payment_instrument_id' => $this->currentRecurContributionlastContribution['payment_instrument_id'],
      'financial_type_id' => $this->currentRecurContributionlastContribution['financial_type_id'],
      'is_test' => $this->currentRecurContributionlastContribution['is_test'],
      'contribution_status_id' => $this->contributionPendingStatusValue,
      'campaign_id' => $this->currentRecurContributionlastContribution['campaign_id'],
      'is_pay_later' => TRUE,
      'membership_id' => $this->currentRecurContributionlastContribution['membership_id'],
      'tax_amount' => $this->currentRecurContributionlastContribution['tax_amount'],
      'skipLineItem' => 1,
      'contribution_recur_id' => $this->currentRecurContributionlastContribution['contribution_recur_id'],
      'soft_credit' => $this->currentRecurContributionlastContribution['soft_credit'],
    ];

    return $params;
  }

  private function calculateInstallmentReceiveDate($contributionNumber) {
    $firstDate = $this->currentRecurContribution['start_date'];
    $intervalFrequency = $this->currentRecurContribution['frequency_interval'];
    $frequencyUnit = $this->currentRecurContribution['frequency_unit'];
    $cycleDay = $this->currentRecurContribution['cycle_day'];

    $firstInstallmentDate = new DateTime($firstDate);
    $numberOfIntervals = ($contributionNumber - 1) * $intervalFrequency;

    switch ($frequencyUnit) {
      case 'day':
        $interval = "P{$numberOfIntervals}D";
        break;

      case 'week':
        $interval = "P{$numberOfIntervals}W";
        break;

      case 'month':
        $interval = "P{$numberOfIntervals}M";
        break;

      case 'year':
        $interval = "P{$numberOfIntervals}Y";
        break;
    }

    $firstInstallmentDate->add(new DateInterval($interval));

    return $firstInstallmentDate->format('Y-m-d');
  }


  private function createLineItem(CRM_Contribute_BAO_Contribution $contribution) {
    $lineItemAmount = $contribution->total_amount;
    if (!empty($contribution->tax_amount)) {
      $lineItemAmount -= $contribution->tax_amount;
    }

    $lineItemParms = [
      'entity_table' => 'civicrm_membership',
      'entity_id' => $this->currentRecurContributionlastContribution['membership_id'],
      'contribution_id' => $contribution->id,
      'label' => 'Membership Type Name', // TODO : membersihp type title
      'qty' => 1,
      'unit_price' => $lineItemAmount,
      'line_total' => $lineItemAmount,
      'financial_type_id' => $contribution->financial_type_id,
      'tax_amount' => $contribution->tax_amount,
    ];

    $lineItem = CRM_Price_BAO_LineItem::create($lineItemParms);

    CRM_Financial_BAO_FinancialItem::add($lineItem, $contribution);
    if (!empty($contribution->tax_amount)) {
      CRM_Financial_BAO_FinancialItem::add($lineItem, $contribution, TRUE);
    }

    /**
     *     $lineItem =civicrm_api3('LineItem', 'create', [
    'entity_table' => 'civicrm_membership',
    'entity_id' => $this->lastContribution['membership_id'],
    'contribution_id' => $contribution->id,
    'label' => '', // TODO : membersihp type title
    'qty' => 1,
    'unit_price' => $contribution->total_amount,
    'line_total' => $contribution->total_amount,
    'financial_type_id' => $contribution->financial_type_id,
    ])['values'][0];

    civicrm_api3('FinancialItem', 'create', [
    'contact_id' => $contribution->contact_id,
    'description' => '', // TODO : membersihp type title
    'amount' => $contribution->total_amount,
    'currency' => $contribution->currency,
    'financial_type_id' => $contribution->financial_type_id,
    'status_id' => 'Unpaid',
    'entity_table' => 'civicrm_line_item',
    'entity_id' => $lineItem['id'],
    'transaction_date' => date('Y-m-d H:i:s'), // TODO : ??
    ]);
     */
  }

}
