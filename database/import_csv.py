#!/usr/bin/env python3
"""
Import CSV files from Db/ directory into PostgreSQL tables.
CSV format: semicolon-delimited, with headers, @-prefixed columns are metadata (skip).
"""

import csv
import os
import sys
import subprocess

DB_NAME = "newds"
DB_USER = "newds"
DB_HOST = "127.0.0.1"
DB_PASSWORD = "Rfghbtkjd%22"

BASE_DIR = "/var/www/newds/Db"

# Map CSV filename (without .csv) to actual table name
# Only needed when they differ
TABLE_NAME_MAP = {
    "webUser": "WebUser",
}

# Map CSV column names to actual table column names
# Only needed when they differ
COLUMN_NAME_MAP = {
    "country": {"order": "order_field"},
}

# Files to skip (no matching table)
SKIP_FILES = {"person"}

# Import order: dictionaries first, then main entities, then dependent data
IMPORT_ORDER = [
    # 1. Core dictionaries (no FK dependencies)
    "Dictionaries/currency.csv",
    "Dictionaries/country.csv",
    "Dictionaries/status.csv",
    "Dictionaries/statuses.csv",
    "Dictionaries/status_levels.csv",
    "Dictionaries/status_requisites.csv",
    "Dictionaries/status_contest.csv",
    "Dictionaries/contractStatus.csv",
    "Dictionaries/commissionCalcProperty.csv",
    "Dictionaries/consultantPaymentStatus.csv",
    "Dictionaries/counterparty.csv",
    "Dictionaries/occupation.csv",
    "Dictionaries/indicator.csv",
    "Dictionaries/termContract.csv",
    "Dictionaries/type_contest.csv",
    "Dictionaries/type_criterion.csv",
    "Dictionaries/riskProfile.csv",
    "Dictionaries/roles.csv",
    "Dictionaries/meetingType.csv",
    "Dictionaries/title.csv",
    "Dictionaries/typeMailConsultantStatus.csv",
    "Dictionaries/directory_of_activities.csv",
    "Dictionaries/usergroups.csv",
    "Dictionaries/city.csv",
    "Dictionaries/agreementPartnersDocuments.csv",
    "Dictionaries/calculationsConstant.csv",
    "Dictionaries/vat.csv",
    "Dictionaries/currencyRate.csv",
    "Dictionaries/profitability.csv",
    "Dictionaries/pattern.csv",
    "Dictionaries/hansardYearsCalcProperty.csv",
    "Dictionaries/setup.csv",

    # 2. Products
    "Products/productCategory.csv",
    "Products/productTags.csv",
    "Products/productType.csv",
    "Products/motivationGroup.csv",
    "Products/product.csv",
    "Products/motivationGroupLevel.csv",
    "Products/program.csv",
    "Products/dsCommission.csv",
    "Products/comissionByLevel.csv",
    "Products/productMatrix.csv",

    # 3. Users
    "webUser.csv",

    # 4. Consultants
    "Consultants data/consultant.csv",
    "Consultants data/consultantLevel.csv",
    "Consultants data/structure.csv",
    "Consultants data/team.csv",
    "Consultants data/consultantStructure.csv",
    "Consultants data/requisites.csv",
    "Consultants data/bankrequisites.csv",
    "Consultants data/logAcceptance.csv",
    "Consultants data/partnerAcceptance.csv",
    "Consultants data/consultantMotivationGroupLevel.csv",
    "Consultants data/consultantStatusChangeMailing.csv",
    "Consultants data/changeConsultantStructureTrigger.csv",
    "Consultants data/chageConsultanStatusLog.csv",
    "Consultants data/notification.csv",
    "Consultants data/user_status_log.csv",
    "Consultants data/consultantProgramsData.csv",
    "Consultants data/temp_password_creation.csv",

    # 6. Clients
    "Clients data/client.csv",
    "Clients data/clientFamily.csv",
    "Clients data/clientGoal.csv",
    "Clients data/clientGoalsTrigger.csv",
    "Clients data/clientsCapital.csv",
    "Clients data/clientsIndicators.csv",
    "Clients data/assetsHistory.csv",
    "Clients data/indicatorsHistory.csv",
    "Clients data/meeting.csv",

    # 7. Contracts
    "Contracts/contract.csv",
    "Contracts/nocomission.csv",
    "Contracts/test.csv",

    # 8. Transactions
    "Transactions/transaction.csv",
    "Transactions/commission.csv",
    "Transactions/transactionDeleting.csv",
    "Transactions/transactionRecalculation.csv",

    # 9. Transaction imports
    "Transactions/Transactions Import/statusImportTransaction.csv",
    "Transactions/Transactions Import/importTransactionLog.csv",
    "Transactions/Transactions Import/unilife.csv",
    "Transactions/Transactions Import/investorsTrust.csv",
    "Transactions/Transactions Import/woodville.csv",
    "Transactions/Transactions Import/privateEquity.csv",
    "Transactions/Transactions Import/broker.csv",
    "Transactions/Transactions Import/bkc.csv",
    "Transactions/Transactions Import/gga.csv",
    "Transactions/Transactions Import/roboadvisor.csv",
    "Transactions/Transactions Import/anderida.csv",
    "Transactions/Transactions Import/ibCounter.csv",
    "Transactions/Transactions Import/changeContractDsCommisionTrigger.csv",
    "Transactions/Transactions Import/createImportTransaction.csv",
    "Transactions/Transactions Import/importtransactionfromn8n.csv",
    "Transactions/Transactions Import/errorN8nlog.csv",

    # 10. Calculations
    "Transactions/Calculations/massTransactionRecalculationTrigger.csv",
    "Transactions/Calculations/vatChangesTrigger.csv",
    "Transactions/Calculations/currencyRatesChangesTrigger.csv",

    # 11. Qualifications
    "Qualifications/qualificationLog.csv",
    "Qualifications/qualificationCalculationsTrigger.csv",
    "Qualifications/qualificationSavingTrigger.csv",
    "Qualifications/consultantsWithMissingLogs.csv",
    "Qualifications/unactualQlogs.csv",

    # 12. Payments
    "Payments/consultantBalance.csv",
    "Payments/consultantPayment.csv",
    "Payments/firstBalances.csv",
    "Payments/unactualBalances.csv",

    # 13. Pool
    "Pool/networkGroupBonus.csv",
    "Pool/poolLog.csv",
    "Pool/poolTrigger.csv",

    # 14. Contest
    "Contest/Contest.csv",
    "Contest/criterion.csv",
    "Contest/coefficientCriterion.csv",
    "Contest/contestrating.csv",
    "Contest/calculationConsultantPoints.csv",
    "Contest/calculationConsultantRaiting.csv",
    "Contest/calculationContestTrigger.csv",

    # 15. Communication
    "Communication/communicationCategory.csv",
    "Communication/platformCommunication.csv",

    # 16. Documents
    "Documents/documentlogs.csv",

    # 17. System
    "System/SocialUser.csv",
    "System/GlobalVariables.csv",
    "System/ResetPasswordRequest.csv",
    "System/WebUserSession.csv",
    "System/cronMonthly.csv",
    "System/cronPartnerCompressionDaily.csv",
    "System/triggerCron.csv",
    "System/Logs/SystemMessage.csv",
    "System/Logs/ImportInformation.csv",
    "System/Logs/SystemException.csv",

    # 18. Integrations - CBR
    "Integrations/CBR/currencyRates.csv",
    "Integrations/CBR/cbrResponse.csv",
    "Integrations/CBR/objectForRequestScenario.csv",

    # 19. Integrations - GetCourse webhooks
    "Integrations/Webhooks/GetCourse/getCourseRegistrationWebHookData.csv",
    "Integrations/Webhooks/GetCourse/getCourseOrderWebHookData.csv",
    "Integrations/Webhooks/GetCourse/getcourseExportTransactionsData.csv",
    "Integrations/Webhooks/GetCourse/getCourseLog.csv",
    "Integrations/Webhooks/GetCourse/getcourseCreateResidentPromocodeDebit.csv",
    "Integrations/Webhooks/GetCourse/getCourseTransactionsFromGoogleSpreadsheetsWebHookData.csv",

    # 20. Integrations - Insmart
    "Integrations/Webhooks/Insmart/insmartVender.csv",
    "Integrations/Webhooks/Insmart/insmartProduct.csv",
    "Integrations/Webhooks/Insmart/getInsmartOrderWebHookData.csv",
    "Integrations/Webhooks/Insmart/webHookInsmartError.csv",

    # 21. Integrations - Google Spreadsheet
    "Integrations/Google Spreadsheet/getcourseTransactionExportDataFromGoogleSpreadsheet.csv",

    # 22. Integrations - N8N
    "Integrations/N8N/lastN8nSyncTimestam.csv",
    "Integrations/N8N/exportLogTransactions.csv",
    "Integrations/N8N/exportLogContract.csv",
    "Integrations/N8N/exportLogClients.csv",
    "Integrations/N8N/exportLogQualificationLog.csv",
    "Integrations/N8N/exportLogConsultant.csv",
    "Integrations/N8N/logExportClient.csv",
    "Integrations/N8N/logExportContract.csv",
    "Integrations/N8N/logExportTransaction.csv",
    "Integrations/N8N/logExportConsultant.csv",

    # 23. Integrations - Crypto
    "Integrations/Crypto/CryptoWallet.csv",
    "Integrations/Crypto/CryptoTransaction.csv",
    "Integrations/Crypto/NearTransaction.csv",

    # 24. Integrations - Telegram
    "Integrations/Telegram/TKeyboard.csv",
    "Integrations/Telegram/TChat.csv",
    "Integrations/Telegram/TUser.csv",
    "Integrations/Telegram/TMessageIn.csv",
    "Integrations/Telegram/TMessageOut.csv",

    # 25. Integrations - Email/SMS/WebFlow/Zapier
    "Integrations/Email/MailLog.csv",
    "Integrations/Email/IncomingMessage.csv",
    "Integrations/WebFlow/WebFlowAccess.csv",
    "Integrations/Zapier/ZapierHook.csv",
    "Integrations/SMS/SmsLog.csv",

    # 26. Reports (non-report_* tables)
    "Reports/monthlyReportAvailabilityIndicator.csv",
    "Reports/reportGenerator.csv",
    "Reports/monthlyReports.csv",
    "Reports/commissionsReport.csv",
    "Reports/partnerMonthlyPaymentsReportMailing.csv",
    "Reports/partnerMonthlyPaymentsReportTrigger.csv",
    "Reports/reportLogs.csv",

    # 27. Data permutation
    "Data permutation/changeConsultantInviterLog.csv",
    "Data permutation/changeConsultantClientLog.csv",
    "Data permutation/changeConsultantContractLog.csv",
    "Data permutation/dataPermutationTrigger.csv",

    # 28. Analytics
    "Analytics/clientsCounterHistory.csv",
    "Analytics/consultantsCounterHistory.csv",

    # 29. Volume calculator
    "Volume calculator/volumeCalculator.csv",
    "Volume calculator/volumeCalculatorHistoryCleaner.csv",

    # 30. Remaining root files
    "backofficeregistration.csv",
    "acts.csv",
    "FileUpload.csv",
    "test_connection_between_cons_and_team.csv",
]


def get_table_name(csv_path):
    """Derive table name from CSV filename."""
    basename = os.path.splitext(os.path.basename(csv_path))[0]
    if basename in SKIP_FILES:
        return None
    return TABLE_NAME_MAP.get(basename, basename)


def get_table_columns(table_name):
    """Get actual column names from PostgreSQL table."""
    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD
    result = subprocess.run(
        [
            "psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST,
            "-t", "-A", "-c",
            f"""SELECT column_name FROM information_schema.columns
                WHERE table_name = '{table_name}' AND table_schema = 'public'
                ORDER BY ordinal_position;"""
        ],
        capture_output=True, text=True, env=env
    )
    cols = [line.strip() for line in result.stdout.strip().split("\n") if line.strip()]
    return cols


def read_csv_header(csv_path):
    """Read the header row of a CSV file."""
    with open(csv_path, "r", encoding="utf-8") as f:
        reader = csv.reader(f, delimiter=";", quotechar='"')
        header = next(reader)
    return header


def run_sql(sql):
    """Execute SQL statement."""
    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD
    result = subprocess.run(
        ["psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST, "-c", sql],
        capture_output=True, text=True, env=env
    )
    if result.returncode != 0 and result.stderr:
        print(f"  SQL error: {result.stderr.strip()}")
    return result


def import_csv(csv_path, table_name):
    """Import a single CSV file into a PostgreSQL table."""
    full_path = os.path.join(BASE_DIR, csv_path)
    if not os.path.exists(full_path):
        print(f"  SKIP (file not found): {csv_path}")
        return False

    csv_header = read_csv_header(full_path)
    table_cols = get_table_columns(table_name)

    if not table_cols:
        print(f"  SKIP (table not found): {table_name}")
        return False

    # Get column name mapping for this table
    col_map = COLUMN_NAME_MAP.get(table_name, {})

    # Build list of columns to import (skip @-prefixed columns)
    import_cols = []
    import_indices = []
    for i, col in enumerate(csv_header):
        if col.startswith("@"):
            continue
        mapped_col = col_map.get(col, col)
        if mapped_col in table_cols:
            import_cols.append(mapped_col)
            import_indices.append(i)
        else:
            print(f"  WARNING: CSV column '{col}' (mapped: '{mapped_col}') not in table '{table_name}', skipping")

    if not import_cols:
        print(f"  SKIP (no matching columns): {table_name}")
        return False

    # Create temp CSV with only the columns we need
    temp_path = f"/tmp/import_{table_name}.csv"
    row_count = 0
    with open(full_path, "r", encoding="utf-8") as fin, \
         open(temp_path, "w", encoding="utf-8", newline="") as fout:
        reader = csv.reader(fin, delimiter=";", quotechar='"')
        writer = csv.writer(fout, delimiter=";", quotechar='"', quoting=csv.QUOTE_MINIMAL)

        # Skip header
        next(reader)

        for row in reader:
            if not row or (len(row) == 1 and not row[0].strip()):
                continue
            # Pad row if shorter than expected
            while len(row) <= max(import_indices):
                row.append("")
            new_row = []
            for idx in import_indices:
                val = row[idx] if idx < len(row) else ""
                # Convert empty strings to empty (PostgreSQL will treat as NULL with FORCE_NULL)
                new_row.append(val)
            writer.writerow(new_row)
            row_count += 1

    if row_count == 0:
        print(f"  SKIP (no data rows): {table_name}")
        os.remove(temp_path)
        return False

    # Build column list for COPY
    cols_str = ", ".join(f'"{c}"' for c in import_cols)
    force_null_str = ", ".join(f'"{c}"' for c in import_cols)

    # Use \copy to import
    copy_sql = f"\\copy \"{table_name}\" ({cols_str}) FROM '{temp_path}' WITH (FORMAT csv, DELIMITER ';', QUOTE '\"', NULL '', FORCE_NULL ({force_null_str}))"

    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD
    result = subprocess.run(
        ["psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST, "-c", copy_sql],
        capture_output=True, text=True, env=env
    )

    os.remove(temp_path)

    if result.returncode != 0:
        print(f"  ERROR: {result.stderr.strip()}")
        return False

    print(f"  OK: {row_count} rows imported")
    return True


def update_sequences():
    """Update all sequences to max(id) + 1 for each table."""
    print("\n=== Updating sequences ===")
    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD

    # Get all tables with integer id columns
    result = subprocess.run(
        [
            "psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST,
            "-t", "-A", "-c",
            """SELECT table_name FROM information_schema.columns
               WHERE column_name = 'id' AND table_schema = 'public'
               AND data_type IN ('integer', 'bigint')
               ORDER BY table_name;"""
        ],
        capture_output=True, text=True, env=env
    )

    tables = [t.strip() for t in result.stdout.strip().split("\n") if t.strip()]
    for table in tables:
        seq_name = f"{table}_id_seq"
        sql = f"""DO $$ BEGIN
            IF EXISTS (SELECT 1 FROM pg_sequences WHERE schemaname = 'public' AND sequencename = '{seq_name}') THEN
                PERFORM setval('"{seq_name}"', COALESCE((SELECT MAX(id) FROM "{table}"), 0) + 1, false);
            END IF;
        END $$;"""
        run_sql(sql)

    print("Sequences updated.")


def main():
    print("=== Disabling triggers (FK constraints) ===")
    run_sql("SET session_replication_role = 'replica';")

    # We need to keep this session setting, so we'll pass it with each import
    # Actually, session_replication_role only works within a session.
    # Instead, let's disable triggers per table.

    # Get all tables
    env = os.environ.copy()
    env["PGPASSWORD"] = DB_PASSWORD
    result = subprocess.run(
        [
            "psql", "-U", DB_USER, "-d", DB_NAME, "-h", DB_HOST,
            "-t", "-A", "-c",
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public';"
        ],
        capture_output=True, text=True, env=env
    )
    all_tables = [t.strip() for t in result.stdout.strip().split("\n") if t.strip()]

    print(f"Disabling triggers on {len(all_tables)} tables...")
    for t in all_tables:
        run_sql(f'ALTER TABLE "{t}" DISABLE TRIGGER ALL;')

    print("\n=== Importing CSV files ===")
    success = 0
    failed = 0
    skipped = 0

    for csv_path in IMPORT_ORDER:
        if csv_path is None:
            continue

        table_name = get_table_name(csv_path)
        if table_name is None:
            print(f"\n[SKIP] {csv_path} (in skip list)")
            skipped += 1
            continue

        print(f"\n[{table_name}] <- {csv_path}")
        if import_csv(csv_path, table_name):
            success += 1
        else:
            failed += 1

    print(f"\n=== Re-enabling triggers ===")
    for t in all_tables:
        run_sql(f'ALTER TABLE "{t}" ENABLE TRIGGER ALL;')

    update_sequences()

    print(f"\n=== Done ===")
    print(f"Success: {success}, Failed/Skipped: {failed + skipped}")


if __name__ == "__main__":
    main()
