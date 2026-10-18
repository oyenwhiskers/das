<?php

namespace App\Enums;

enum CustomerActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Archived = 'archived';
    case Restored = 'restored';
    case RelatedPersonAdded = 'related_person_added';
    case RelatedPersonUpdated = 'related_person_updated';
    case RelationshipLinked = 'relationship_linked';
    case RelationshipRemoved = 'relationship_removed';
    case NextOfKinUpdated = 'next_of_kin_updated';
    case EmploymentAdded = 'employment_added';
    case EmploymentUpdated = 'employment_updated';
    case EmploymentEnded = 'employment_ended';
    case PrimaryEmploymentChanged = 'primary_employment_changed';
    case EmploymentStatusUpdated = 'employment_status_updated';
    case EmploymentRecordCorrected = 'employment_record_corrected';
    case BankAccountAdded = 'bank_account_added';
    case BankAccountUpdated = 'bank_account_updated';
    case PrimaryBankAccountChanged = 'primary_bank_account_changed';
    case BankAccountDeactivated = 'bank_account_deactivated';
    case BankAccountReactivated = 'bank_account_reactivated';
    case MalaysianTinAdded = 'malaysian_tin_added';
    case MalaysianTinUpdated = 'malaysian_tin_updated';
    case TaxResidencyAdded = 'tax_residency_added';
    case TaxResidencyUpdated = 'tax_residency_updated';
    case TaxResidencyEnded = 'tax_residency_ended';
    case FatcaDeclarationCreated = 'fatca_declaration_created';
    case FatcaDeclarationUpdated = 'fatca_declaration_updated';
    case InvestmentProfileCreated = 'investment_profile_created';
    case FinancialDeclarationCreated = 'financial_declaration_created';
    case FinancialDeclarationUpdated = 'financial_declaration_updated';
    case BrokerageDeclarationCreated = 'brokerage_declaration_created';
    case BrokerageDeclarationUpdated = 'brokerage_declaration_updated';
    case InvestmentProfileUpdated = 'investment_profile_updated';
    case BrokerageAccountAdded = 'brokerage_account_added';
    case BrokerageAccountUpdated = 'brokerage_account_updated';
    case BrokerageAccountDeactivated = 'brokerage_account_deactivated';
    case BrokerageAccountReactivated = 'brokerage_account_reactivated';
    case PepDeclarationCreated = 'pep_declaration_created';
    case PepDeclarationUpdated = 'pep_declaration_updated';
    case BankruptcyDeclarationCreated = 'bankruptcy_declaration_created';
    case BankruptcyDeclarationUpdated = 'bankruptcy_declaration_updated';
    case ComplianceDeclarationReconfirmed = 'compliance_declaration_reconfirmed';
    case PepRelatedPersonAdded = 'pep_related_person_added';
    case PepRelatedPersonUpdated = 'pep_related_person_updated';
    case PepAssociationEnded = 'pep_association_ended';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Customer created',
            self::Updated => 'Customer updated',
            self::Archived => 'Customer archived',
            self::Restored => 'Customer restored',
            self::RelatedPersonAdded => 'Related person added',
            self::RelatedPersonUpdated => 'Related person updated',
            self::RelationshipLinked => 'Relationship linked',
            self::RelationshipRemoved => 'Relationship removed',
            self::NextOfKinUpdated => 'Next of kin updated',
            self::EmploymentAdded => 'Employment information added',
            self::EmploymentUpdated => 'Employment information updated',
            self::EmploymentEnded => 'Employment ended',
            self::PrimaryEmploymentChanged => 'Primary employment changed',
            self::EmploymentStatusUpdated => 'Employment status updated',
            self::EmploymentRecordCorrected => 'Employment record corrected',
            self::BankAccountAdded => 'Bank account added',
            self::BankAccountUpdated => 'Bank account updated',
            self::PrimaryBankAccountChanged => 'Primary bank account changed',
            self::BankAccountDeactivated => 'Bank account deactivated',
            self::BankAccountReactivated => 'Bank account reactivated',
            self::MalaysianTinAdded => 'Malaysian TIN added',
            self::MalaysianTinUpdated => 'Malaysian TIN updated',
            self::TaxResidencyAdded => 'Tax residency added',
            self::TaxResidencyUpdated => 'Tax residency updated',
            self::TaxResidencyEnded => 'Tax residency ended',
            self::FatcaDeclarationCreated => 'FATCA declaration created',
            self::FatcaDeclarationUpdated => 'FATCA declaration updated',
            self::InvestmentProfileCreated => 'Investment profile created',
            self::FinancialDeclarationCreated => 'Financial declaration created',
            self::FinancialDeclarationUpdated => 'Financial declaration updated',
            self::BrokerageDeclarationCreated => 'Brokerage declaration created',
            self::BrokerageDeclarationUpdated => 'Brokerage declaration updated',
            self::InvestmentProfileUpdated => 'Investment profile updated',
            self::BrokerageAccountAdded => 'Brokerage account added',
            self::BrokerageAccountUpdated => 'Brokerage account updated',
            self::BrokerageAccountDeactivated => 'Brokerage account deactivated',
            self::BrokerageAccountReactivated => 'Brokerage account reactivated',
            self::PepDeclarationCreated => 'PEP declaration created',
            self::PepDeclarationUpdated => 'PEP declaration updated',
            self::BankruptcyDeclarationCreated => 'Bankruptcy declaration created',
            self::BankruptcyDeclarationUpdated => 'Bankruptcy declaration updated',
            self::ComplianceDeclarationReconfirmed => 'Compliance declaration reconfirmed',
            self::PepRelatedPersonAdded => 'PEP-related person added',
            self::PepRelatedPersonUpdated => 'PEP-related person updated',
            self::PepAssociationEnded => 'PEP association ended',
        };
    }
}
