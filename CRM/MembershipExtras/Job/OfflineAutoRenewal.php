<?php

use CRM_MembershipExtras_Service_MembershipInstallmentsHandler as MembershipInstallmentsHandler;

class CRM_MembershipExtras_Job_OfflineAutoRenewal {

  /**
   * Mapping between financial types IDs and Machine Names.
   *
   * @var array
   */
  private $financialTypesIDMap = [];


  /**
   * The ID of the membership that currently
   * being renewed/processed.
   *
   * @var int
   */
  private $currentMembershipID;

  /**
   * The ID of the recurring Contribution linked
   * with the membership that currently
   * being renewed/processed.
   *
   * @var int
   */
  private $currentRecurContributionID;

  /**
   * The number of installments of the membership that currently
   * being renewed/processed.
   *
   * @var int
   */
  private $currentInstallmentsNumber;

  /**
   * The total amount that to be used
   * to create the recurring contribution as well as
   * the installment contributions.
   *
   * @var int
   */
  private $totalAmount;

  /**
   * Should the membership latest price
   * be used for renewal or the old one.
   *
   * @var bool
   */
  private $useMembershipLatestPrice = FALSE;


  public function __construct() {
    $this->setFinancialTypesIDMap();
    $this->setUseMembershipLatestPrice();
  }

  /**
   * Sets $financialTypesIDMap
   */
  private function setFinancialTypesIDMap() {
    $financialTypes = civicrm_api3('FinancialType', 'get', [
      'sequential' => 1,
      'return' => ['id', 'name'],
      'options' => ['limit' => 0],
    ])['values'];

    foreach ($financialTypes as $type) {
      $this->financialTypesIDMap[$type['id']] = $type['name'];
    }
  }

  /**
   * Sets $useMembershipLatestPrice
   */
  private function setUseMembershipLatestPrice() {
    $settingFieldName = 'membershipextras_paymentplan_use_membership_latest_price';
    $this->useMembershipLatestPrice = civicrm_api3('Setting', 'get', array(
      'sequential' => 1,
      'return' => [$settingFieldName],
    ))['values'][0][$settingFieldName];
  }

  /**
   * Starts the scheduled job for renewing offline
   * auto-renewal memberships.
   *
   * @return True
   */
  public function run() {
   $membershipsToRenew = $this->getOfflineAutoRenewalMemberships();
   foreach ($membershipsToRenew as $membership) {
     $this->currentMembershipID = $membership['membership_id'];
     $this->currentRecurContributionID = $membership['contribution_recur_id'];
     $this->currentInstallmentsNumber = $membership['installments'];

     $this->totalAmount = $membership['total_amount'];
     if ($this->useMembershipLatestPrice && !$membership['optout_last_price_offline_autorenew']) {
       $this->totalAmount = $this->calculateSingleInstallmentAmount(
         $membership['membership_minimum_fee'], $this->currentInstallmentsNumber
       );
     }

     if ($this->currentInstallmentsNumber > 1) {
       $this->renewWithInstallmentsMembership();
     }
     else {
       $this->renewNoInstallmentsMembership();
     }
   }

   return TRUE;
  }

  /**
   * Gets the list of offline auto-renewal memberships
   * to be renewed, the membership should satisfy the following
   * conditions for it to be auto-renewed :
   * 1- the membership is set to auto renew (has a linked recurring contribution).
   * 2- the payment processor used is pay later (aka : no payment processor used)
   *   or an equivalent payment processor.
   * 3- The linked recurring contribution is not cancelled or refunded.
   * 4- The membership end date is less or equal than today.
   *
   * @return array
   *   Each membership row Contains :
   *   - The membership ID (membership_id)
   *   - The membership current "minimum fee"/"price" (membership_minimum_fee)
   *   - The linked  recurring contribution (contribution_recur_id)
   *   - The number of the linked recurring contribution installments (installments)
   *   - The previous membership total paid amount (total_amount)
   *   - The membership optout_last_price_offline_autorenew custom field value (optout_last_price_offline_autorenew)
   */
  private function getOfflineAutoRenewalMemberships() {
    $membershipsList = [];

    $getContributionStatusesNameMap = $this->getContributionStatusesNameMap();
    $cancelledStatusID = $getContributionStatusesNameMap['Cancelled'];
    $refundedStatusID = $getContributionStatusesNameMap['Refunded'];

    $payLaterPaymentProcessors = new CRM_MembershipExtras_PaymentProcessor_OfflineRecurringContribution();
    $payLaterPaymentProcessorsIDs = implode(',', [0, $payLaterPaymentProcessors->get()['id']]);

    $query = 'SELECT cm.id as membership_id, cmt.minimum_fee as membership_minimum_fee,
                ccr.id as contribution_recur_id, ccr.installments , ccr.amount as contribution_recur_amount,
                cvoao.optout_last_price_offline_autorenew 
              FROM civicrm_membership cm
              INNER JOIN civicrm_contribution_recur ccr
                ON cm.contribution_recur_id = ccr.id
              LEFT JOIN civicrm_membership_type cmt 
                ON cm.membership_type_id = cmt.id 
              LEFT JOIN civicrm_value_offline_autorenew_option cvoao  
                ON cm.id = cvoao.entity_id 
              WHERE ccr.auto_renew = 1 
                AND (ccr.payment_processor_id IS NULL OR ccr.payment_processor_id IN (' . $payLaterPaymentProcessorsIDs . '))
                AND (ccr.contribution_status_id != ' . $cancelledStatusID . ' OR  ccr.contribution_status_id != ' . $refundedStatusID . ')
                AND cm.end_date <= CURDATE()';
    $memberships = CRM_Core_DAO::executeQuery($query);

    while ($memberships->fetch()) {
      $membershipsList['membership_id'] = $memberships['membership_id'];
      $membershipsList['membership_minimum_fee'] = $memberships['membership_minimum_fee'];
      $membershipsList['contribution_recur_id'] = $memberships['contribution_recur_id'];
      $membershipsList['installments'] = $memberships['installments'];
      $membershipsList['total_amount'] = $memberships['contribution_recur_amount'];
      $membershipsList['optout_last_price_offline_autorenew'] = $memberships['optout_last_price_offline_autorenew'];
    }

    return $membershipsList;
  }

  /**
   * Gets contribution Statuses Name to value Mapping
   *
   * @return array $contributionStatusesNameMap
   */
  private function getContributionStatusesNameMap() {
    $contributionStatuses = civicrm_api3('OptionValue', 'get', [
      'sequential' => 1,
      'return' => ['name', 'value'],
      'option_group_id' => 'contribution_status',
      'options' => ['limit' => 0],
    ])['values'];

    $contributionStatusesNameMap = [];
    foreach ($contributionStatuses as $status) {
      $contributionStatusesNameMap[$status['name']] = $status['value'];
    }

    return $contributionStatusesNameMap;
  }

  /**
   * Calculates a single installment amount (price) if there is more than one
   * installment.
   *
   * If there is only one installment then its amount will be the total amount.
   *
   * @param float $totalAmount
   * @param int $installmentsCount
   *
   * @return float
   */
  private function calculateSingleInstallmentAmount($totalAmount, $installmentsCount) {
    $amount =  $totalAmount;
    if ($installmentsCount > 1) {
      $amount = floor(($totalAmount / $installmentsCount) * 100) / 100;
    }

    return $amount;
  }


  /**
   * Renews the membership if
   * it paid by installments.
   */
  private function renewWithInstallmentsMembership() {
    $previousRecurContributionId = $this->currentRecurContributionID;
    $this->createRecurringContribution();

    $installmentsHandler = new MembershipInstallmentsHandler(
      $this->currentRecurContributionID,
      $previousRecurContributionId
    );
    $installmentsHandler->createFirstInstallmentContribution($this->totalAmount);
    $installmentsHandler->createRemainingInstalmentContributionsUpfront();

    $this->renewMembership();
  }

  /**
   * Renews the current membership recurring contribution
   * by creating a new one based on its data.
   *
   * Then new recurring contribution will then
   * be set to be the current recurring contribution.
   */
  private function createRecurringContribution() {
    $currentRecurContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $this->currentRecurContributionID,
    ])['values'][0];


    $paymentProcessorID = !empty($currentRecurContribution['payment_processor_id']) ? $currentRecurContribution['payment_processor_id'] : NULL;

    $newRecurringContribution = civicrm_api3('ContributionRecur', 'create', [
      'sequential' => 1,
      'contact_id' => $currentRecurContribution['contact_id'],
      'amount' => $this->totalAmount,
      'currency' => $currentRecurContribution['currency'],
      'frequency_unit' => $currentRecurContribution['frequency_unit'],
      'frequency_interval' => $currentRecurContribution['frequency_interval'],
      'installments' => $currentRecurContribution['installments'],
      'contribution_status_id' => 'In Progress',
      'is_test' => $currentRecurContribution['is_test'],
      'auto_renew' => 1,
      'cycle_day' => $currentRecurContribution['cycle_day'],
      'payment_processor_id' => $paymentProcessorID,
      'financial_type_id' => $this->financialTypesIDMap[$currentRecurContribution['financial_type_id']],
      'payment_instrument_id' =>'EFT',
      'campaign_id' => $currentRecurContribution['campaign_id'],
    ])['values'][0];

    // The new recurring contribution is now the current one.
    $this->currentRecurContributionID = $newRecurringContribution['id'];
  }

  /**
   * Renews the membership if
   * it paid by once and not using installments.
   *
   * Paid by once (no installments) membership
   * get renewed by creating single pending contribution
   * that links to the already existing recurring
   * contribution.
   *
   */
  private function renewNoInstallmentsMembership() {
    $installmentsHandler = new CRM_MembershipExtras_Service_MembershipInstallmentsHandler($this->currentRecurContributionID);
    $installmentsHandler->createFirstInstallmentContribution($this->totalAmount);

    $this->renewMembership();
  }

  /**
   * Renews/Extend the membership to be auto-renewed
   * by one term.
   */
  private function renewMembership() {
    $membershipDetails = civicrm_api3('Membership', 'get', [
      'sequential' => 1,
      'return' => ['contact_id', 'membership_type_id', 'is_test', 'campaign_id'],
      'id' => $this->currentMembershipID,
    ])['values'][0];

    CRM_Member_BAO_Membership::processMembership(
      $membershipDetails['contact_id'], $membershipDetails['membership_type_id'], $membershipDetails['is_test'],
      NULL, NULL, NULL, 1, $this->currentMembershipID,
      FALSE,
      $this->currentRecurContributionID, NULL, TRUE, $membershipDetails['campaign_id']
    );
  }

}
