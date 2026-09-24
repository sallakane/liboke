<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920152031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE donation (id UUID NOT NULL, stripe_session_id VARCHAR(255) NOT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, stripe_customer_id VARCHAR(255) DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, type VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(255) NOT NULL, donor_name VARCHAR(180) DEFAULT NULL, donor_email VARCHAR(180) DEFAULT NULL, donor_address_line VARCHAR(255) DEFAULT NULL, donor_postal_code VARCHAR(32) DEFAULT NULL, donor_city VARCHAR(120) DEFAULT NULL, donor_country VARCHAR(2) DEFAULT NULL, is_company BOOLEAN NOT NULL, receipt_number VARCHAR(40) DEFAULT NULL, receipt_issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_31E581A01A314A57 ON donation (stripe_session_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_31E581A0B0ADB74C ON donation (receipt_number)');
        $this->addSql('CREATE INDEX idx_donation_created_at ON donation (created_at)');
        $this->addSql('CREATE INDEX idx_donation_status ON donation (status)');
        $this->addSql('CREATE TABLE stripe_event (event_id VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (event_id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE donation');
        $this->addSql('DROP TABLE stripe_event');
    }
}
