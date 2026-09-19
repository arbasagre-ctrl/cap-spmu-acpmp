const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

const notificationService = read('app/Services/NotificationService.php');
const requestWorkflow = read('app/Services/RequestWorkflowService.php');
const custodyService = read('app/Services/CustodyService.php');
const deadlines = read('app/Console/Commands/ProcessOperationalDeadlines.php');
const accountabilityController = read('app/Http/Controllers/AccountabilityController.php');
const lateReturnNotice = read('resources/views/documents/accountability/late-return-notice.blade.php');
const notificationView = read('resources/views/notifications/index.blade.php');

test('approval email uses plain borrower-facing wording and includes the initial pickup schedule', () => {
  assert.match(requestWorkflow, /Your borrowing request \{\$request->request_no\} has been approved\. Pickup is scheduled for/);
  assert.match(notificationService, /Pickup Schedule/);
  assert.match(notificationService, /Pickup Deadline/);
  assert.match(notificationService, /Approved Items/);
  assert.doesNotMatch(notificationService, /Inventory Status/);
  assert.doesNotMatch(notificationService, /Claim Until/);
  assert.doesNotMatch(notificationService, /valid SPMU operating window/);
  assert.doesNotMatch(notificationService, /SPMU Operational Calendar/);
});

test('automatic approval-time pickup activation does not send a second PICKUP_SCHEDULED notification', () => {
  const start = custodyService.indexOf('public function ensurePickupRecord(');
  const end = custodyService.indexOf('public function expirePickupWindows(', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const method = custodyService.slice(start, end);

  assert.match(method, /REQUEST_APPROVED notification/);
  assert.doesNotMatch(method, /notifications->send\(\s*'PICKUP_SCHEDULED'/);
});

test('PICKUP_SCHEDULED means an updated schedule, not the initial approval schedule', () => {
  assert.match(notificationService, /'PICKUP_SCHEDULED' => 'Pickup Schedule Updated'/);
  assert.match(notificationService, /Your pickup schedule has been updated/);
});

test('items released stays as an in-system confirmation instead of another email', () => {
  const start = custodyService.indexOf("'ITEMS_RELEASED'");
  assert.notEqual(start, -1);
  const block = custodyService.slice(start, start + 1100);
  assert.match(block, /\['SYSTEM'\]/);
  assert.doesNotMatch(block, /\['SYSTEM', 'EMAIL'\]/);
  assert.match(block, /Please return them on or before/);
});

test('return reminders avoid operational-calendar terminology', () => {
  assert.match(deadlines, /Reminder: the borrowed items under/);
  assert.match(notificationService, /'RETURN_DUE_TODAY' => 'Return Due Today'/);
  assert.match(notificationService, /'RETURN_DUE_TOMORROW' => 'Return Due Tomorrow'/);
  assert.doesNotMatch(deadlines, /effective SPMU operational return date/);
});

test('email template avoids internal system wording', () => {
  assert.match(notificationService, /Sent \{\$this->escape\(\$footerTimestamp\)\}\./);
  assert.doesNotMatch(notificationService, /Notification recorded/);
  assert.doesNotMatch(notificationService, /Relevant Items/);
});


test('in-system notification headings use friendly event labels', () => {
  assert.match(notificationView, /'PICKUP_SCHEDULED' => 'Pickup Schedule Updated'/);
  assert.match(notificationView, /'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' => 'Property Accountability Decision'/);
  assert.match(notificationView, /'PAYMENT_VERIFIED' => 'Payment Confirmed'/);
  assert.doesNotMatch(notificationView, /A system update was recorded/);
});

test('email delivery does not attach generated PDFs because documents stay behind authenticated preview', () => {
  assert.match(notificationService, /Mail::html/);
  assert.doesNotMatch(notificationService, /->attach\s*\(/);
  assert.doesNotMatch(notificationService, /attachData\s*\(/);
});

test('late return notice states the daily rate but never prints the assessed total amount', () => {
  assert.match(lateReturnNotice, /Official Late-Return Fee Rate/);
  assert.doesNotMatch(lateReturnNotice, /Assessed Amount/);
  assert.match(lateReturnNotice, /total amount due is intentionally not stated in this notice/i);

  const overdueStart = notificationService.indexOf('if ($source instanceof OverdueCase)');
  const overdueEnd = notificationService.indexOf('if ($source instanceof BillingStatement)', overdueStart);
  const overdueBlock = notificationService.slice(overdueStart, overdueEnd);
  assert.match(overdueBlock, /Official Daily Late-Return Fee/);
  assert.match(overdueBlock, /Late Return Billing Statement/);
});

test('billing-required late return produces one borrower email instead of two back-to-back emails', () => {
  const noticeIndex = accountabilityController.indexOf("'LATE_RETURN_NOTICE_ISSUED'");
  const billingIndex = accountabilityController.indexOf("'LATE_RETURN_BILLING_STATEMENT_ISSUED'", noticeIndex);
  assert.notEqual(noticeIndex, -1);
  assert.notEqual(billingIndex, -1);

  const noticeBlock = accountabilityController.slice(noticeIndex, billingIndex);
  assert.match(noticeBlock, /\['SYSTEM'\]/);
  assert.doesNotMatch(noticeBlock, /\['SYSTEM', 'EMAIL'\]/);

  const billingBlock = accountabilityController.slice(billingIndex, billingIndex + 1100);
  assert.match(billingBlock, /\['SYSTEM', 'EMAIL'\]/);
  assert.match(billingBlock, /Preview it in My Obligations/);
});
