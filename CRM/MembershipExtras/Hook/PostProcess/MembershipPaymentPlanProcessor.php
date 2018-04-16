<?php

class CRM_MembershipExtras_Hook_PostProcess_MembershipPaymentPlanProcessor {

  private $form;

  public function __construct(&$form) {
    $this->form = &$form;
  }

  public function process() {
    if (!$this->isPaymentPlanPayment()) {
      return;
    }

    $recurContributionID = $this->getMembershipLastRecurContributionID();
    $installmentsHandler = new CRM_MembershipExtras_MembershipInstallmentsHandler($recurContributionID);
    $installmentsHandler->createRemainingInstalmentContributionsUpfront();
  }

  private function isPaymentPlanPayment() {
    $installmentsCount = CRM_Utils_Request::retrieve('installments', 'Int');
    $isSavingContribution = CRM_Utils_Request::retrieve('record_contribution', 'Int');
    $contributionIsPaymentPlan = CRM_Utils_Request::retrieve('contribution_type_toggle', 'String') === 'payment_plan';

    if ($isSavingContribution && $contributionIsPaymentPlan && $installmentsCount > 1) {
      return TRUE;
    }

    return FALSE;
  }

  private function getMembershipLastRecurContributionID() {
    $membershipID = $this->form->_id;

    $recurContributionID = civicrm_api3('MembershipPayment', 'get', [
      'sequential' => 1,
      'return' => ['contribution_id.contribution_recur_id'],
      'membership_id' => $membershipID,
      'options' => ['limit' => 1, 'sort' => 'contribution_id.contribution_recur_id DESC'],
    ])['values'][0]['contribution_id.contribution_recur_id'];

    return $recurContributionID;
  }

}
