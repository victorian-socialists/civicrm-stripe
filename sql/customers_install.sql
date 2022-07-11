-- /*******************************************************
-- *
-- * Create new tables
-- *
-- *******************************************************/

-- /*******************************************************
-- *
-- * civicrm_stripe_customers
-- *
-- * Stripe Customers
-- *
-- *******************************************************/
CREATE TABLE `civicrm_stripe_customers` (
  `id` varchar(255) COMMENT 'Stripe Customer ID',
  `contact_id` int unsigned COMMENT 'FK to Contact',
  `processor_id` int unsigned COMMENT 'ID from civicrm_payment_processor',
  UNIQUE INDEX `id`(id),
  CONSTRAINT FK_civicrm_stripe_customers_contact_id FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact`(`id`) ON DELETE CASCADE
)
ENGINE=InnoDB;
