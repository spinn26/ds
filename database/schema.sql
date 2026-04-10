CREATE TABLE "acts" (
  "id" int PRIMARY KEY,
  "status" varchar,
  "name" varchar
);

CREATE TABLE "agreementPartnersDocuments" (
  "id" int PRIMARY KEY,
  "number" int,
  "link" varchar,
  "name" varchar
);

CREATE TABLE "anderida" (
  "id" int PRIMARY KEY,
  "paymentdate" timestamp,
  "contract" varchar,
  "contractLink" int,
  "currency" varchar,
  "contractIsEmpty" boolean,
  "amountIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "amount" decimal,
  "importTransactionLog" int,
  "currencyIsEmpty" boolean
);

CREATE TABLE "assetsHistory" (
  "id" int PRIMARY KEY,
  "currency" int,
  "assetAnnualIncome" decimal,
  "valueUsd" decimal,
  "liabilityPeriod" int,
  "amount" decimal,
  "date" timestamp,
  "liabilityPercentage" decimal,
  "dateFroReport" varchar,
  "liabilityMonthlyPayment" decimal,
  "asset" int
);

CREATE TABLE "backofficeregistration" (
  "id" int PRIMARY KEY,
  "email" varchar,
  "roles" int,
  "firstName" varchar,
  "patronymic" varchar,
  "lastName" varchar
);

CREATE TABLE "bankrequisites" (
  "id" int PRIMARY KEY,
  "status" int,
  "beneficiaryOgrn" varchar,
  "paymentPurpose" varchar,
  "branchAddress" varchar,
  "bankInn" varchar,
  "headers" varchar,
  "swiftCode" varchar,
  "requisites" int,
  "bankName" varchar,
  "beneficiaryName" varchar,
  "urlData" varchar,
  "bankContact" varchar,
  "bankAddress" varchar,
  "beneficiaryAddress" varchar,
  "comment" varchar,
  "WebUser" int,
  "correspondentAccount" varchar,
  "branchName" varchar,
  "accountNumber" varchar,
  "bankBik" varchar,
  "beneficiaryInn" varchar,
  "deletedAt" timestamp,
  "verified" boolean,
  "dateChange" timestamp
);

CREATE TABLE "bkc" (
  "id" int PRIMARY KEY,
  "contractIsEmpty" boolean,
  "contract" varchar,
  "contractLink" int,
  "paymentdateIsEmpty" boolean,
  "paymentdate" timestamp,
  "amount" decimal,
  "revenue" decimal,
  "amountIsEmpty" boolean,
  "importTransactionLog" int,
  "client" varchar
);

CREATE TABLE "broker" (
  "id" int PRIMARY KEY,
  "paymentdate" timestamp,
  "contract" varchar,
  "contractLink" int,
  "contractIsEmpty" boolean,
  "amountTransaction" decimal,
  "amountIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "client" varchar,
  "amount" decimal,
  "importTransactionLog" int
);

CREATE TABLE "calculationConsultantPoints" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "qualificationLog" int,
  "contest" int,
  "sumPoints" decimal,
  "dateQualificationLog" timestamp,
  "coefficientPersonalVolume" int,
  "coefficientGroupVolume" int,
  "coefficientGroupVolumeCumulative" int,
  "transaction" int,
  "contracts" int,
  "qualification" int,
  "commissions" int,
  "date" varchar
);

CREATE TABLE "calculationConsultantRaiting" (
  "id" int PRIMARY KEY,
  "raitingArraySorted" json,
  "raitingArray" json,
  "arrayPoints" int,
  "contest" int
);

CREATE TABLE "calculationContestTrigger" (
  "id" int PRIMARY KEY,
  "sumPersonalVolumeContest" decimal,
  "commissionsProduct" int,
  "contracts" int,
  "reportSumPoints" int,
  "reportActive" int,
  "sumCommissionContest" decimal,
  "contractsCount" int,
  "commissionsAmount" int,
  "commissions" int,
  "consultant" int,
  "qualificationsCount" int,
  "sumContractSum" decimal,
  "transactionAmount" int,
  "commissionsProductAmount" int,
  "qualifications" int,
  "contractsAmount" int,
  "createDate" timestamp,
  "transaction" int,
  "qualificationLog" int,
  "sumGroupVolumeContest" decimal,
  "transactionCount" int,
  "consultantName" text,
  "commissionsCount" int,
  "qualificationsAmount" int,
  "qualificationLogActive" int,
  "sumGroupCumVolumeContest" decimal,
  "qualificationLogCount" int,
  "contest" int,
  "volumeActiveCount" int,
  "commissionsProductCount" int,
  "sumTransactionContest" decimal,
  "sumContract" int,
  "sumRevenueContest2" decimal
);

CREATE TABLE "calculationsConstant" (
  "id" int PRIMARY KEY,
  "title" varchar,
  "value" decimal
);

CREATE TABLE "cbrResponse" (
  "id" int PRIMARY KEY,
  "currencyRates" int,
  "currencyDictionary" int
);

CREATE TABLE "chageConsultanStatusLog" (
  "id" int PRIMARY KEY,
  "webUser" int,
  "headers" varchar,
  "urlData" varchar,
  "dateCreated" timestamp,
  "consultant" int
);

CREATE TABLE "changeConsultantClientLog" (
  "id" int PRIMARY KEY,
  "dateCreated" timestamp,
  "webUser" int,
  "client" int,
  "clientName" varchar,
  "consultantOld" int,
  "consultantNew" int,
  "consultantOldName" varchar,
  "consultantNewName" varchar,
  "triggeredBy" varchar
);

CREATE TABLE "changeConsultantContractLog" (
  "id" int PRIMARY KEY,
  "dateCreated" timestamp,
  "webUser" int,
  "contract" int,
  "contractNumber" varchar,
  "consultantOld" int,
  "consultantNew" int,
  "consultantOldName" varchar,
  "consultantNewName" varchar,
  "triggeredBy" varchar
);

CREATE TABLE "changeConsultantInviterLog" (
  "id" int PRIMARY KEY,
  "dateCreated" timestamp,
  "webUser" int,
  "inviterNew" int,
  "inviterOld" int,
  "triggeredBy" varchar,
  "consultant" int,
  "consultantName" varchar,
  "inviterNewName" varchar,
  "inviterOldName" varchar
);

CREATE TABLE "changeConsultantStructureTrigger" (
  "id" int PRIMARY KEY,
  "allConsultants" int,
  "level7" int,
  "level4" int,
  "level6" int,
  "user_field" int,
  "level3" int,
  "level5" int,
  "level8" int,
  "level10" int,
  "level2" int,
  "level9" int,
  "level1" int
);

CREATE TABLE "changeContractDsCommisionTrigger" (
  "id" int PRIMARY KEY,
  "contract" int,
  "dsCommission" int,
  "dsCommissionToRemove" int,
  "user_field" int,
  "headers" varchar,
  "urlData" varchar,
  "date" timestamp
);

CREATE TABLE "city" (
  "id" int PRIMARY KEY,
  "cityNameRu" varchar,
  "cityNameEn" varchar,
  "country" int,
  "countryName" varchar
);

CREATE TABLE "client" (
  "id" int PRIMARY KEY,
  "leadDs" boolean,
  "consultant" int,
  "family" int,
  "workSince" timestamp,
  "active" boolean,
  "urlData" varchar,
  "dateChanged" timestamp,
  "assetsArray" int,
  "goalsArray" int,
  "idDs" varchar,
  "products" int,
  "investingStartDate" timestamp,
  "consultantName" varchar,
  "comment" varchar,
  "occupation" int,
  "source" varchar,
  "lastActivityDate" timestamp,
  "deathDate" timestamp,
  "dateDeleted" timestamp,
  "investingEndDate" timestamp,
  "headers" varchar,
  "indicators" int,
  "personName" varchar,
  "webUser" int,
  "dateCreated" timestamp,
  "contracts" int,
  "citizenship" int,
  "changedAt" timestamp,
  "person" int,
  "changeConsultantClientLog" int
);

CREATE TABLE "clientFamily" (
  "id" int PRIMARY KEY,
  "deleted" boolean,
  "relationship" varchar,
  "birthdate" timestamp,
  "client" int,
  "name" varchar,
  "occupation" int
);

CREATE TABLE "clientGoal" (
  "id" int PRIMARY KEY,
  "WebUser" int,
  "headers" varchar,
  "averageInflationInUsd" int,
  "plan" varchar,
  "type" varchar,
  "currency" int,
  "urlData" varchar,
  "achievementDate" timestamp,
  "name" varchar,
  "initialInvestmentUSD" decimal,
  "monthlyInvestmentUSD" decimal,
  "initialInvestment" decimal,
  "riskProfile" int,
  "monthlyInvestmentBasedOnInitialInvestmentUSD" decimal,
  "requiredCapitalWithInflationUSD" decimal,
  "valueUsd" decimal,
  "amount" decimal,
  "priority" int,
  "client" int
);

CREATE TABLE "clientGoalsTrigger" (
  "id" int PRIMARY KEY,
  "riskProfile" int,
  "goals" int
);

CREATE TABLE "clientsCapital" (
  "id" int PRIMARY KEY,
  "category" boolean,
  "currency" int,
  "assetAnnualIncome" decimal,
  "goal" varchar,
  "startingValue" decimal,
  "valueUsd" decimal,
  "purpose" varchar,
  "liabilityPeriod" int,
  "amount" decimal,
  "client" int,
  "name" varchar,
  "liabilityPercentage" decimal,
  "acquisitionDate" timestamp,
  "type" boolean,
  "place" varchar,
  "liabilityMonthlyPayment" decimal,
  "city" int
);

CREATE TABLE "clientsCounterHistory" (
  "id" int PRIMARY KEY,
  "active" int,
  "date" timestamp,
  "archive" int,
  "total" int
);

CREATE TABLE "clientsIndicators" (
  "id" int PRIMARY KEY,
  "client" int,
  "date" timestamp,
  "indicator" int,
  "value" decimal,
  "currency" int,
  "valueUsd" decimal
);

CREATE TABLE "coefficientCriterion" (
  "id" int PRIMARY KEY,
  "levelQualification" int,
  "criterion" int,
  "numericValue" decimal,
  "contest" int,
  "type" varchar
);

CREATE TABLE "comissionByLevel" (
  "id" int PRIMARY KEY,
  "program" int,
  "level" int,
  "commissionCalcProperty" int,
  "comission" decimal,
  "commissionAbsolute" decimal
);

CREATE TABLE "commission" (
  "id" int PRIMARY KEY,
  "transaction" int,
  "consultant" int,
  "comment" varchar,
  "techComment" varchar,
  "amount" decimal,
  "amountRUB" decimal,
  "amountUSD" decimal,
  "currency" int,
  "chainOrder" int,
  "type" varchar,
  "commissionFromOtherConsultant" int,
  "user_field" int,
  "urlData" varchar,
  "detele" boolean,
  "headers" varchar,
  "personalVolume" decimal,
  "groupVolume" decimal,
  "groupBonus" decimal,
  "groupBonusRub" decimal,
  "groupVolumeCumulative" decimal,
  "groupVolumeCumulativeUpdate" decimal,
  "createdAt" timestamp,
  "deletedAt" timestamp,
  "date" timestamp,
  "dateDay" timestamp,
  "dateMonth" varchar,
  "dateYear" varchar,
  "reduction" boolean,
  "consultantsChain" int,
  "groupBonusRubBeforeGapReduction" decimal,
  "withheldPercent" decimal,
  "withheldForCommission" decimal,
  "withheldForGap" decimal,
  "qualificationLog" int,
  "calculationLevel" int,
  "percent" decimal,
  "absolute" decimal
);

CREATE TABLE "commissionCalcProperty" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "commissionsReport" (
  "id" int PRIMARY KEY,
  "WebUser" int,
  "balancesReport" int,
  "month" timestamp,
  "headers" varchar,
  "urlData" varchar
);

CREATE TABLE "communicationCategory" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "consultant" (
  "id" int PRIMARY KEY,
  "applicationForPayment" int,
  "urlData" varchar,
  "passportScanPage1" int,
  "commissionLast" int,
  "person" int,
  "structureLevel" int,
  "title" int,
  "webUser" int,
  "statusRequisites" int,
  "groupVolumeCumulative" decimal,
  "dateCreated" timestamp,
  "dateDeactivity" timestamp,
  "soldProducts" int,
  "status_and_lvl" int,
  "dateDeterministicPlan" timestamp,
  "qualificationLog" int,
  "clients" int,
  "personName" varchar,
  "transactionProrostId" int,
  "inviterName" varchar,
  "active" boolean,
  "ambassadorProductNames" varchar,
  "passportScanPage2" int,
  "ambassadorForProducts" int,
  "statusesName" varchar,
  "fieldForReport" boolean,
  "acceptance" boolean,
  "personalVolume" decimal,
  "changeConsultantInvitertLog" int,
  "dateDeterministic" timestamp,
  "inviter" int,
  "groupVolume" decimal,
  "soldPrograms" int,
  "upperLevels" int,
  "contracts" int,
  "requisites" int,
  "bankRequisitesForPayments" int,
  "dateActivity" timestamp,
  "tempRequisites" int,
  "transactionProrost" json,
  "structure" int,
  "participantCode" varchar,
  "isStudent" boolean,
  "headers" varchar,
  "dateChangeRequisites" timestamp,
  "transactionSPFK" json,
  "dateDeleted" timestamp,
  "team" int,
  "country" int,
  "dateChanged" timestamp,
  "commissionAPI" json,
  "changedAt" timestamp,
  "invited" int,
  "activity" int,
  "qualificationLocked" timestamp,
  "status" int,
  "comment" varchar,
  "transactionSpfkId" int,
  "agreementlink" int,
  "partnerIdGetCourse" varchar
);

CREATE TABLE "consultantBalance" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "consultantPersonName" varchar,
  "status" varchar,
  "comment" varchar,
  "personalSales" decimal,
  "groupSales" decimal,
  "personalSalesBonus" decimal,
  "personalSalesBonusRub" decimal,
  "groupSalesBonus" decimal,
  "groupSalesBonusRub" decimal,
  "salesBonus" decimal,
  "personalSalesVolume" decimal,
  "groupSalesVolume" decimal,
  "accruedNonTransactionalScore" decimal,
  "bonusPlusPoolRub" decimal,
  "accruedAmountHasBeenRedused" boolean,
  "withheldForCommissions" decimal,
  "withheldForGap" decimal,
  "groupBonusRubBeforeGapReduction" decimal,
  "dateCreated" timestamp,
  "dateDay" timestamp,
  "dateMonth" varchar,
  "dateYear" varchar,
  "createdAt" timestamp,
  "paymentComfirmedByDate" timestamp,
  "partnerMonthlyPaymentsReport" int,
  "reportSent" boolean,
  "mailerResponse" varchar,
  "webUser" int,
  "headers" varchar,
  "urlData" varchar,
  "application" varchar,
  "balance" decimal,
  "accruedTransactional" decimal,
  "accruedNonTransactional" decimal,
  "accruedPool" decimal,
  "accruedTotal" decimal,
  "totalPayable" decimal,
  "payed" decimal,
  "remaining" decimal,
  "qualificationLog" int,
  "consultantPayments" int,
  "groupSalesTransactions" int
);

CREATE TABLE "consultantLevel" (
  "id" int PRIMARY KEY,
  "level" int,
  "min" int,
  "max" int
);

CREATE TABLE "consultantMotivationGroupLevel" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "level" int,
  "date" timestamp,
  "dateFinish" timestamp,
  "active" boolean,
  "volume" decimal
);

CREATE TABLE "consultantPayment" (
  "id" int PRIMARY KEY,
  "consultantBalance" int,
  "amount" decimal,
  "paymentDate" timestamp,
  "status" int,
  "comment" varchar,
  "webUser" int,
  "urlData" varchar,
  "headers" varchar
);

CREATE TABLE "consultantPaymentStatus" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "consultantProgramsData" (
  "id" int PRIMARY KEY,
  "consultantNextLevelScoreLeft" int,
  "productContractsCounter" int,
  "contractScore" int,
  "product" int,
  "programContractsSumm" int,
  "consultant" int,
  "consultantLevelPercentage" decimal,
  "programContractsScore" int,
  "productType" int,
  "consultantLevel" int,
  "program" int,
  "productContractsSumm" int,
  "contract" int,
  "programContractsCounter" int,
  "consultantScore" int,
  "productContractsScore" int,
  "consultantContractsCounter" int
);

CREATE TABLE "consultantsCounterHistory" (
  "id" int PRIMARY KEY,
  "date" timestamp,
  "value" int
);

CREATE TABLE "consultantStatusChangeMailing" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "dateCreated" timestamp,
  "comment" varchar,
  "type" int
);

CREATE TABLE "consultantStructure" (
  "id" int PRIMARY KEY,
  "child" int,
  "parent" int
);

CREATE TABLE "consultantsWithMissingLogs" (
  "id" int PRIMARY KEY,
  "hasLostLogs" boolean,
  "consultant" int,
  "name" varchar,
  "firstLogBeforeMissingOne" int
);

CREATE TABLE "Contest" (
  "id" int PRIMARY KEY,
  "visibilityConsultants" boolean,
  "conditionalTurnOn" boolean,
  "urlData" varchar,
  "headers" varchar,
  "webUser" int,
  "techComment" varchar,
  "archiveDate" timestamp,
  "end" timestamp,
  "program" int,
  "nameNumericValue" varchar,
  "name" varchar,
  "type" int,
  "description" varchar,
  "visibilityResidents" boolean,
  "createdAt" timestamp,
  "status" int,
  "visibility" varchar,
  "product" int,
  "typeEvent" varchar,
  "start" timestamp,
  "banner" int,
  "resultsPublicationDate" timestamp,
  "presentation" varchar,
  "updatedAt" timestamp,
  "criterion" int,
  "numberOfWinners" int,
  "numericValueFact" decimal,
  "numericValue" decimal
);

CREATE TABLE "contestrating" (
  "id" int PRIMARY KEY,
  "contest" int,
  "consultant" int,
  "points" decimal,
  "position" int,
  "progressPercentagePrize" decimal,
  "progressPercentageTop" decimal,
  "createdAt" timestamp,
  "updatedAt" timestamp,
  "changedDirection" boolean,
  "personName" varchar,
  "date" varchar
);

CREATE TABLE "contract" (
  "id" int PRIMARY KEY,
  "term" int,
  "type" varchar,
  "programsArray" int,
  "product" int,
  "paymentCount" int,
  "ammount" decimal,
  "amountRUB" decimal,
  "productName" varchar,
  "createDate" timestamp,
  "currency" int,
  "consultantsChain" int,
  "student" varchar,
  "closeDate" timestamp,
  "programName" varchar,
  "comment" varchar,
  "clientResidency" varchar,
  "headers" varchar,
  "number" varchar,
  "setup" int,
  "webUser" int,
  "changedAt" timestamp,
  "valueUsd" decimal,
  "changeConsultantContractLog" int,
  "country" int,
  "program" int,
  "getCourseOrderWebHookData" int,
  "clientName" varchar,
  "status" int,
  "client" int,
  "dsCommission" int,
  "clearOpenDate" boolean,
  "contractScore" decimal,
  "consultantCountry" int,
  "urlData" varchar,
  "consultantsChainLevels" int,
  "groupVolume" decimal,
  "createdAt" timestamp,
  "counterpartyContractId" varchar,
  "openDate" timestamp,
  "orderIdGetCourse" varchar,
  "deletedAt" timestamp,
  "riskProfile" int,
  "getInsmartOrderWebHookData" int,
  "consultantName" varchar,
  "personalVolume" decimal,
  "consultant" int
);

CREATE TABLE "contractStatus" (
  "id" int PRIMARY KEY,
  "name" varchar
);

CREATE TABLE "counterparty" (
  "id" int PRIMARY KEY,
  "counterpartyName" varchar,
  "alias" varchar
);

CREATE TABLE "country" (
  "id" int PRIMARY KEY,
  "order_field" int,
  "countryNameRu" varchar,
  "countryNameEn" varchar
);

CREATE TABLE "createImportTransaction" (
  "id" int PRIMARY KEY,
  "importTransactionLog" int,
  "webUser" int,
  "urlData" varchar,
  "headers" varchar
);

CREATE TABLE "criterion" (
  "id" int PRIMARY KEY,
  "contest" int,
  "type" int,
  "name" varchar,
  "numericValue" decimal,
  "qualificationValue" int,
  "coefficients" boolean,
  "createdAt" timestamp,
  "updatedAt" timestamp,
  "webUser" int,
  "headers" varchar,
  "urlData" varchar,
  "delete" boolean,
  "numericValueTurnOn" boolean,
  "coefficientDelete" int,
  "product" int,
  "program" int
);

CREATE TABLE "cronMonthly" (
  "id" int PRIMARY KEY
);

CREATE TABLE "cronPartnerCompressionDaily" (
  "id" int PRIMARY KEY,
  "monthlyReportAvailability" int,
  "consultans" int,
  "monthlyReportAvailabilityPrevious" int
);

CREATE TABLE "CryptoTransaction" (
  "id" int PRIMARY KEY,
  "nonce" int,
  "blockHash" varchar,
  "transactionIndex" int,
  "gasPrice" varchar,
  "cumulativeGasUsed" varchar,
  "confirmations" int,
  "isError" varchar,
  "txreceipt_status" varchar,
  "network" varchar,
  "blockNumber" int,
  "timeStamp" varchar,
  "hash" varchar,
  "from_field" varchar,
  "to_field" int,
  "value" varchar,
  "contractAddress" varchar,
  "input" varchar,
  "gas" varchar,
  "gasUsed" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "CryptoWallet" (
  "id" int PRIMARY KEY,
  "network" varchar,
  "address" varchar,
  "privateKey" varchar,
  "publicKey" varchar,
  "is_encrypted" boolean,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "currency" (
  "id" int PRIMARY KEY,
  "isoNum" int,
  "cbrCode" varchar,
  "nameRu" varchar,
  "nameEn" varchar,
  "nominal" int,
  "symbol" varchar,
  "rate" decimal,
  "currencyName" varchar,
  "priority" int
);

CREATE TABLE "currencyRate" (
  "id" int PRIMARY KEY,
  "currency" int,
  "rate" decimal,
  "date" timestamp
);

CREATE TABLE "currencyRates" (
  "id" int PRIMARY KEY,
  "nominal" varchar,
  "rate" varchar
);

CREATE TABLE "currencyRatesChangesTrigger" (
  "id" int PRIMARY KEY,
  "user_field" int,
  "eur" decimal,
  "gbp" decimal,
  "createdAt" timestamp,
  "urlData" json,
  "headers" json,
  "period" timestamp,
  "usd" decimal
);

CREATE TABLE "dataPermutationTrigger" (
  "id" int PRIMARY KEY,
  "period" timestamp,
  "user_field" int,
  "triggeredBy" varchar,
  "monthlyReportAvailabilityIndicator" int,
  "comment" varchar,
  "status" varchar,
  "consultant" int,
  "clientAmount" int,
  "clientCounter" int,
  "client" int,
  "invitedAmount" int,
  "inviteCounter" int,
  "invited" int,
  "contractAmount" int,
  "contractCounter" int,
  "contract" int
);

CREATE TABLE "directory_of_activities" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "comment" varchar
);

CREATE TABLE "documentlogs" (
  "id" int PRIMARY KEY,
  "date" varchar,
  "dateCreated" timestamp,
  "type" varchar,
  "consultantBalance" int,
  "qualifications_old" int,
  "qualifications" int,
  "qualificationsOldJSON" json,
  "qualificationsJSON" json,
  "qualificationsJSONmerge" json,
  "commissions" int,
  "commissionsJSON" json,
  "file" json,
  "consultantBalanceJSON" json,
  "webUser" int,
  "urlData" varchar,
  "headers" varchar,
  "revenueProduct" int,
  "revenueRevenue" int,
  "revenueExpenses" int,
  "revenueJSON" json,
  "revenueCustom1" json,
  "revenueCustom2" json,
  "consultant" int,
  "qualificationLog" int,
  "comissions" int,
  "comissions2" int,
  "customJSON1" json,
  "customJSON2" json,
  "customJSON3" json,
  "comissions3" int,
  "customJSON4" json,
  "consultantBalance2" int,
  "consultantPAyments" int,
  "resultFile" int
);

CREATE TABLE "dsCommission" (
  "id" int PRIMARY KEY,
  "product" int,
  "dateFinish" timestamp,
  "comission" decimal,
  "programName" varchar,
  "dateDeleted" timestamp,
  "active" boolean,
  "program" int,
  "commissionCalcProperty" int,
  "productName" varchar,
  "commissionAbsolute" decimal,
  "date" timestamp,
  "termContract" int
);

CREATE TABLE "errorN8nlog" (
  "id" int PRIMARY KEY,
  "comment" varchar
);

CREATE TABLE "exportLogClients" (
  "id" int PRIMARY KEY,
  "jsonToSend" json,
  "log" varchar,
  "lastN8nSync" int,
  "exportedDate" timestamp,
  "clients" int,
  "n8nResponse" json
);

CREATE TABLE "exportLogConsultant" (
  "id" int PRIMARY KEY,
  "jsonToSend" json,
  "log" varchar,
  "lastN8nSync" int,
  "consultants" int,
  "exportedDate" timestamp,
  "n8nResponse" json
);

CREATE TABLE "exportLogContract" (
  "id" int PRIMARY KEY,
  "jsonToSend" json,
  "log" varchar,
  "lastN8nSync" int,
  "contracts" int,
  "exportedDate" timestamp,
  "n8nResponse" json
);

CREATE TABLE "exportLogQualificationLog" (
  "id" int PRIMARY KEY,
  "jsonToSend" json,
  "log" varchar,
  "lastN8nSync" int,
  "exportedDate" timestamp,
  "n8nResponse" json,
  "qLogs" int
);

CREATE TABLE "exportLogTransactions" (
  "id" int PRIMARY KEY,
  "jsonToSend" json,
  "log" varchar,
  "lastN8nSync" int,
  "transactions" int,
  "exportedDate" timestamp,
  "n8nResponse" json
);

CREATE TABLE "FileUpload" (
  "size" int,
  "finalFileName" varchar,
  "originalFileName" varchar,
  "extension" varchar,
  "dateCreated" varchar,
  "uuid" int,
  "id" varchar PRIMARY KEY,
  "deletedAt" varchar,
  "folderRaw" varchar,
  "network" varchar,
  "dirtyFile" varchar,
  "uniqFileName" varchar,
  "urlLink" varchar,
  "uploaded" boolean,
  "source" varchar,
  "folder" varchar
);

CREATE TABLE "firstBalances" (
  "id" int PRIMARY KEY,
  "balance" int,
  "consultant" int,
  "date" timestamp,
  "qLog" int
);

CREATE TABLE "getcourseCreateResidentPromocodeDebit" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "webUser" int,
  "dateCreated" timestamp,
  "headers" varchar,
  "urlData" varchar
);

CREATE TABLE "getcourseExportTransactionsData" (
  "id" int PRIMARY KEY,
  "oredrId" varchar,
  "number" varchar,
  "userId" varchar,
  "user_field" varchar,
  "email" varchar,
  "phone" varchar,
  "dateCreated" timestamp,
  "datePayed" timestamp,
  "title" varchar,
  "status" varchar,
  "priceRub" decimal,
  "payed" decimal,
  "gatewayCommission" decimal,
  "received" decimal,
  "tax" decimal,
  "leftover" decimal,
  "otherCommissions" decimal,
  "earned" decimal,
  "currency" varchar,
  "city" varchar,
  "paymentGateway" varchar,
  "partnerId" varchar,
  "promocode" varchar,
  "promoAction" varchar,
  "partnerSource" varchar,
  "partnerCode" varchar,
  "partnerSession" varchar,
  "user_utm_source" varchar,
  "user_utm_medium" varchar,
  "user_utm_campaign" varchar,
  "user_utm_content" varchar,
  "user_gcpc" varchar,
  "createdAt" timestamp,
  "contract" int,
  "transaction" int,
  "contractDeleted" int
);

CREATE TABLE "getCourseLog" (
  "id" int PRIMARY KEY,
  "webHookRegistration" int,
  "webHookOrder" int,
  "error" varchar,
  "getcourseExportTransactionsData" int,
  "done" boolean
);

CREATE TABLE "getCourseOrderWebHookData" (
  "id" int PRIMARY KEY,
  "body" json,
  "headers" json,
  "urlData" json,
  "lastNameClient" varchar,
  "utmMedium" varchar,
  "paymentLink" varchar,
  "partnerFirstName" varchar,
  "payedAt" varchar,
  "clientId" varchar,
  "contractNumber" varchar,
  "participantCode" varchar,
  "offerId" varchar,
  "IDPlatform" varchar,
  "partnerLastName" varchar,
  "clientEmail" varchar,
  "costmoney" varchar,
  "partnerId" varchar,
  "orderId" varchar,
  "utmcampaign" varchar,
  "partnerPhone" varchar,
  "promocode" varchar,
  "positions" varchar,
  "firstNameClient" varchar,
  "partnerEmail" varchar,
  "clientPhone" varchar,
  "status" varchar,
  "utmsource" varchar,
  "payedmoney" varchar,
  "leftcostmoney" varchar,
  "deletedDate" timestamp,
  "comment" varchar,
  "payeddate" timestamp,
  "custom_status" varchar,
  "surname" varchar,
  "responseToIdSaving" json,
  "contractNumberOld" varchar,
  "persona" int,
  "consultant" int,
  "program" int,
  "contract" int,
  "amount" decimal,
  "transaction" int,
  "paramsBase64" varchar,
  "dateCreated" timestamp
);

CREATE TABLE "getCourseRegistrationWebHookData" (
  "id" int PRIMARY KEY,
  "body" json,
  "headers" json,
  "urlData" json,
  "firstName" varchar,
  "lastName" varchar,
  "phone" varchar,
  "city" varchar,
  "surname" varchar,
  "email" varchar,
  "responseToIdSaving" json,
  "participantCode" varchar,
  "nikTG" varchar,
  "bdDate" varchar,
  "IDPlatform" varchar,
  "idGetCourse" varchar,
  "participantCodeClear" varchar,
  "userAccept" varchar,
  "responseToAcceptDocumentSaving" json,
  "dateCreated" timestamp,
  "person" int,
  "paramsBase64" varchar
);

CREATE TABLE "getcourseTransactionExportDataFromGoogleSpreadsheet" (
  "id" int PRIMARY KEY,
  "payed" decimal,
  "earned" decimal,
  "otherCommissions" decimal,
  "user_utm_campaign" varchar,
  "gatewayCommission" decimal,
  "promoAction" varchar,
  "partnerSource" varchar,
  "contract" int,
  "user_utm_content" varchar,
  "partnerId" varchar,
  "user_field" varchar,
  "currency" varchar,
  "user_utm_medium" varchar,
  "sourceData" json,
  "createdAt" timestamp,
  "promocode" varchar,
  "user_gcpc" varchar,
  "number" varchar,
  "userId" varchar,
  "dateCreated" timestamp,
  "city" varchar,
  "email" varchar,
  "tax" decimal,
  "phone" varchar,
  "title" varchar,
  "leftover" decimal,
  "partnerSession" varchar,
  "status" varchar,
  "transaction" int,
  "user_utm_source" varchar,
  "contractDeleted" int,
  "datePayed" timestamp,
  "priceRub" decimal,
  "paymentGateway" varchar,
  "partnerCode" varchar,
  "received" decimal,
  "oredrId" varchar
);

CREATE TABLE "getCourseTransactionsFromGoogleSpreadsheetsWebHookData" (
  "id" int PRIMARY KEY,
  "body" json,
  "headers" json,
  "urlData" json,
  "getcourseExportTransactionsData" int,
  "data" json
);

CREATE TABLE "getInsmartOrderWebHookData" (
  "id" int PRIMARY KEY,
  "body" json,
  "headers" json,
  "urlData" json,
  "event" varchar,
  "idConsultantInsmart" varchar,
  "consultant" int,
  "dateTime" timestamp,
  "orderId" varchar,
  "price" decimal,
  "company" varchar,
  "companyCode" int,
  "insurant" varchar,
  "phoneClient" varchar,
  "emailClient" varchar,
  "status" int,
  "paidAt" timestamp,
  "appCommission" decimal,
  "appCommissionPercentage" decimal,
  "client" int,
  "contract" int,
  "vender" int,
  "productInsmart" varchar,
  "product" int,
  "program" int,
  "appAgentCommission" decimal,
  "appAgentCommissionPercentage" decimal,
  "agentCommission" decimal,
  "agentCommissionPercentage" decimal,
  "upsaleCommission" decimal,
  "dateCreated" timestamp,
  "firstNameClient" varchar,
  "lastNameClient" varchar,
  "surNameClient" varchar,
  "webUser" int,
  "dsCommission" int,
  "transaction" int,
  "comission" decimal,
  "productJson" json,
  "tokenJson" json,
  "token" varchar,
  "productNewInsmart" int,
  "venderNewInsmart" int,
  "venderJson" json
);

CREATE TABLE "gga" (
  "id" int PRIMARY KEY,
  "paymentdate" timestamp,
  "policy" varchar,
  "commissionAbsolute" decimal,
  "contract" varchar,
  "amountContractOriginal" decimal,
  "contractLink" int,
  "product" varchar,
  "program" varchar,
  "contractIsEmpty" boolean,
  "amountIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "amountContractBase" decimal,
  "client" varchar,
  "importTransactionLog" int,
  "comission" decimal
);

CREATE TABLE "GlobalVariables" (
  "id" int PRIMARY KEY,
  "value" varchar
);

CREATE TABLE "hansardYearsCalcProperty" (
  "id" int PRIMARY KEY,
  "term" int,
  "calcProperty" int
);

CREATE TABLE "ibCounter" (
  "id" int PRIMARY KEY,
  "client" varchar,
  "paymentdate" timestamp,
  "contract" varchar,
  "amount" decimal,
  "contractLink" int,
  "paymentIsEmpty" boolean,
  "contractIsEmpty" boolean,
  "amountIsEmpty" boolean,
  "importTransactionLog" int,
  "commissionProperty" int,
  "revenueIbUp" decimal
);

CREATE TABLE "ImportInformation" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "countInserted" int,
  "countUpdated" int,
  "countNotified" int,
  "countRaw" int,
  "countProcessed" int,
  "statusCode" varchar,
  "statusDesc" varchar,
  "dateCreated" varchar,
  "errorMsg" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "importtransactionfromn8n" (
  "id" int PRIMARY KEY,
  "data" json,
  "counterparty" varchar,
  "importTransactionLogFront" int,
  "error" varchar,
  "roboDate" int,
  "roboType" int,
  "roboBase" int,
  "roboContract" int,
  "roboDateCount" int,
  "roboTypeCount" int,
  "roboBaseCount" int,
  "roboContractCount" int,
  "robo" int,
  "roboCount" int,
  "roboContractAmount" int,
  "roboAmount" int,
  "roboBaseAmount" int,
  "roboTypeAmount" int,
  "roboDateAmount" int
);

CREATE TABLE "importTransactionLog" (
  "id" int PRIMARY KEY,
  "n8nStep" boolean,
  "counterparty" varchar,
  "date" varchar,
  "transactions" int,
  "currency" varchar,
  "count" int,
  "ibCounter" int,
  "status" int,
  "urlData" varchar,
  "headers" varchar,
  "transactionsCounter" int,
  "webUser" int
);

CREATE TABLE "IncomingMessage" (
  "id" int PRIMARY KEY,
  "from_field" varchar,
  "to_field" varchar,
  "dateSent" varchar,
  "dateReceived" varchar,
  "subject" varchar,
  "text" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "indicator" (
  "id" int PRIMARY KEY,
  "comment" varchar,
  "name" varchar
);

CREATE TABLE "indicatorsHistory" (
  "id" int PRIMARY KEY,
  "date" timestamp,
  "value" decimal,
  "currency" int,
  "client" int,
  "indicator" int,
  "valueUsd" decimal
);

CREATE TABLE "insmartProduct" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "alias" varchar
);

CREATE TABLE "insmartVender" (
  "id" int PRIMARY KEY,
  "alias" varchar,
  "name" varchar
);

CREATE TABLE "investorsTrust" (
  "id" int PRIMARY KEY,
  "contractIsEmpty" boolean,
  "paymentdate" timestamp,
  "currency" varchar,
  "contract" varchar,
  "amountCommission" decimal,
  "comment" varchar,
  "commission" decimal,
  "amount" decimal,
  "currencyIsEmpty" boolean,
  "dsCommissionsArray" int,
  "importTransactionLog" int,
  "levelDs" int,
  "newDsCommision" int,
  "contractLink" int,
  "yearIsEmpty" boolean,
  "comissionIsEmpty" boolean,
  "paymentdateIsEmpty" boolean,
  "oldDsCommission" int,
  "year" varchar
);

CREATE TABLE "lastN8nSyncTimestam" (
  "id" int PRIMARY KEY,
  "contract" int,
  "transaction" int,
  "client" int,
  "consultant" int,
  "qLog" int
);

CREATE TABLE "logAcceptance" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "dateAccepted" timestamp,
  "WebUser" int,
  "urlData" varchar,
  "headers" varchar,
  "source" varchar,
  "responseToAcceptDocumentSaving" json
);

CREATE TABLE "logExportClient" (
  "id" int PRIMARY KEY,
  "error" varchar,
  "createDate" timestamp,
  "idClient" varchar
);

CREATE TABLE "logExportConsultant" (
  "id" int PRIMARY KEY,
  "createDate" timestamp,
  "error" varchar,
  "idConsultant" varchar
);

CREATE TABLE "logExportContract" (
  "id" int PRIMARY KEY,
  "error" varchar,
  "idContract" varchar,
  "createDate" timestamp
);

CREATE TABLE "logExportTransaction" (
  "id" int PRIMARY KEY,
  "idTransaction" varchar,
  "createDate" timestamp,
  "error" varchar
);

CREATE TABLE "MailLog" (
  "id" int PRIMARY KEY,
  "networkID" int,
  "autopilotID" int,
  "qID" int,
  "emailFrom" varchar,
  "emailTo" varchar,
  "title" varchar,
  "body" varchar,
  "status" varchar,
  "autopilotName" varchar,
  "autopilotUUID" varchar,
  "dateCreated" varchar,
  "open" boolean,
  "emailGatewayID" int,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "massTransactionRecalculationTrigger" (
  "id" int PRIMARY KEY,
  "type" varchar,
  "status" varchar,
  "triggeredBy" varchar,
  "comment" varchar,
  "consultant" int,
  "transactionsWithFees" int,
  "transactionsWithFeesAmount" int,
  "transactionsWithFeesCounter" int,
  "RemovalCommissions" int,
  "RemovalCommissionsAmount" int,
  "RemovalCommissionsCounter" int,
  "user_field" int,
  "urlData" json,
  "headers" json,
  "transactionsWithUnknownPartner" int,
  "transactionsWithUnknownPartnerAmount" int,
  "transactionsWithUnknownPartnerCounter" int,
  "transactions" int,
  "transactionsAmount" int,
  "transactionsCounter" int,
  "transactionsWithKnownPartner" int,
  "consultantsWithCommissions" int,
  "consultantsWithCommissionsAmount" int,
  "qualificationLogs" int,
  "qualificationLogsAmount" int,
  "qualificationLogsCounter" int,
  "consultantBalances" int,
  "consultantBalancesAmount" int,
  "consultantBalancesCounter" int,
  "commissionsCalcTransactionsAmount" int,
  "commissionsCalcTransactionsCounter" int,
  "gapQualificationLogsAmount" int,
  "gapQualificationLogsCounter" int,
  "transactionsWithoutFees" int,
  "transactionsWithoutFeesAmount" int,
  "transactionsWithoutFeesCounter" int,
  "createdAt" timestamp,
  "dateFrom" timestamp,
  "dateTo" timestamp,
  "customCommissionTransactions" int,
  "customCommissionTransactionsAmount" int,
  "customCommissionTransactionsCounter" int,
  "saveDsCommissionAmount" int,
  "saveDsCommissionCounter" int,
  "sendingToBubbleAmount" int,
  "sendingToBubbleCounter" int
);

CREATE TABLE "meeting" (
  "id" int PRIMARY KEY,
  "client" int,
  "date" timestamp,
  "type" int,
  "consultant" int,
  "comment" varchar
);

CREATE TABLE "meetingType" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "monthlyReportAvailabilityIndicator" (
  "id" int PRIMARY KEY,
  "WebUser" int,
  "period" timestamp,
  "headers" varchar,
  "dateChange" timestamp,
  "dateClose" timestamp,
  "urlData" varchar,
  "available" boolean
);

CREATE TABLE "monthlyReports" (
  "id" int PRIMARY KEY,
  "urlData" varchar,
  "reportGenerator" int,
  "file" int,
  "dateFrom" timestamp,
  "WebUser" int,
  "status" varchar,
  "createdAt" timestamp,
  "headers" varchar,
  "type" varchar,
  "consultant" int,
  "dateTo" timestamp
);

CREATE TABLE "motivationGroup" (
  "id" int PRIMARY KEY,
  "title" varchar,
  "products" int
);

CREATE TABLE "motivationGroupLevel" (
  "id" int PRIMARY KEY,
  "max" int,
  "motivationGroup" int,
  "min" int,
  "level" int
);

CREATE TABLE "NearTransaction" (
  "signer_account_id" varchar,
  "id" int PRIMARY KEY,
  "processed" boolean,
  "transaction_hash" varchar,
  "status" varchar,
  "receiver_account_id" int,
  "nonce" varchar,
  "action_kind" varchar,
  "args" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "networkGroupBonus" (
  "id" int PRIMARY KEY,
  "value" decimal,
  "date" timestamp,
  "consultants" int,
  "level6Count" int,
  "level7Count" int,
  "level8Count" int,
  "level9Count" int,
  "level10Count" int,
  "level6PoolBonusAmount" decimal,
  "level7PoolBonusAmount" decimal,
  "level8PoolBonusAmount" decimal,
  "level9PoolBonusAmount" decimal,
  "level10PoolBonusAmount" decimal
);

CREATE TABLE "nocomission" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "comission" decimal,
  "transaction" int,
  "amount" decimal,
  "delete" boolean
);

CREATE TABLE "notification" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "client" int,
  "date" timestamp,
  "comment" varchar
);

CREATE TABLE "objectForRequestScenario" (
  "id" int PRIMARY KEY
);

CREATE TABLE "occupation" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "partnerAcceptance" (
  "id" int PRIMARY KEY,
  "logAccepted" int,
  "WebUser" int,
  "documentType" int,
  "urlData" varchar,
  "sourse" varchar,
  "headers" varchar,
  "accepted" boolean,
  "consultant" int,
  "dateAccepted" timestamp
);

CREATE TABLE "partnerMonthlyPaymentsReportMailing" (
  "id" int PRIMARY KEY,
  "date" varchar,
  "urlData" varchar,
  "response" varchar,
  "headers" varchar,
  "webUser" int,
  "period" varchar,
  "consultants" int
);

CREATE TABLE "partnerMonthlyPaymentsReportTrigger" (
  "id" int PRIMARY KEY,
  "commissions" int,
  "commissionsGroup" int,
  "commissionsPersonal" int,
  "consultantPayments" int,
  "urlData" varchar,
  "headers" varchar,
  "reportHTML" varchar,
  "consultant" int,
  "pool" int,
  "consultantBalance" int,
  "date" timestamp,
  "webUser" int,
  "reportLink" varchar,
  "nonTransactionalCommissions" int
);

CREATE TABLE "pattern" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "idCounterparty" int,
  "rowStart" int,
  "dateTransactionColumn" varchar,
  "contractNumberColumn" varchar,
  "fioColumn" varchar,
  "amountColumn" varchar,
  "typeTransactionColumn" varchar,
  "termNumberColumn" varchar,
  "amountCommissionColumn" varchar
);

CREATE TABLE "platformCommunication" (
  "id" int PRIMARY KEY,
  "author" int,
  "date" timestamp,
  "consultant" int,
  "category" int,
  "direction" varchar,
  "read" boolean,
  "urlData" varchar,
  "message" varchar,
  "WebUser" int,
  "headers" varchar
);

CREATE TABLE "poolLog" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "poolBonus" decimal,
  "networkGroupBonus" int,
  "date" timestamp,
  "createdAt" timestamp
);

CREATE TABLE "poolTrigger" (
  "id" int PRIMARY KEY,
  "createdAt" timestamp,
  "headers" json,
  "user_field" int,
  "date" timestamp,
  "urlData" json,
  "consultants" int
);

CREATE TABLE "privateEquity" (
  "id" int PRIMARY KEY,
  "contract" varchar,
  "importTransactionLog" int,
  "contractLink" int,
  "contractIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "paymentdate" timestamp,
  "amountIsEmpty" boolean,
  "amount" decimal,
  "amountTransaction" decimal,
  "client" varchar
);

CREATE TABLE "product" (
  "id" int PRIMARY KEY,
  "motivationGroup" int,
  "visibleToResident" boolean,
  "visibleToCalculator" boolean,
  "ambassador" int,
  "access" int,
  "formLink" varchar,
  "productType" int,
  "name" varchar,
  "typeName" varchar,
  "noComission" boolean,
  "tagList" varchar,
  "active" boolean,
  "productTags" int
);

CREATE TABLE "productCategory" (
  "id" int PRIMARY KEY,
  "visibleToResident" boolean,
  "productCategoryName" varchar
);

CREATE TABLE "productMatrix" (
  "id" int PRIMARY KEY,
  "vendor" int,
  "productType" int,
  "program" int,
  "provider" int,
  "productCategory" int,
  "product" int
);

CREATE TABLE "productTags" (
  "id" int PRIMARY KEY,
  "productTagName" varchar
);

CREATE TABLE "productType" (
  "id" int PRIMARY KEY,
  "productTypeName" varchar,
  "active" boolean,
  "categoryName" varchar,
  "visibleToResident" boolean,
  "productTypeCategory" int
);

CREATE TABLE "profitability" (
  "id" int PRIMARY KEY,
  "value" decimal,
  "riskProfile" int,
  "currency" int
);

CREATE TABLE "program" (
  "id" int PRIMARY KEY,
  "providerName" varchar,
  "categoryName" varchar,
  "vendorName" varchar,
  "vendor" int,
  "productTypeName" varchar,
  "productName" varchar,
  "visibleToResident" boolean,
  "currencyName" varchar,
  "dateDeleted" timestamp,
  "term" int,
  "provider" int,
  "dsCommission" int,
  "product" int,
  "termContract" int,
  "currency" int,
  "name" varchar,
  "active" boolean,
  "commissionCalcProperty" int,
  "category" int,
  "visibleToCalculator" boolean,
  "productType" int,
  "calcComment" varchar
);

CREATE TABLE "qualificationCalculationsTrigger" (
  "id" int PRIMARY KEY,
  "headers" varchar,
  "date" timestamp,
  "urlData" varchar,
  "consultant" int,
  "all" boolean,
  "triggeredBy" int
);

CREATE TABLE "qualificationLog" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "result" varchar,
  "commissionsToReduce" int,
  "firstLineBranchesJSON" json,
  "levelsDontMatch" boolean,
  "branchWithGap" int,
  "qualificationLogPrevious" int,
  "commissionsToReduceCounter" int,
  "savingDate" timestamp,
  "comment" varchar,
  "branchWithGapGroupVolume" decimal,
  "personalVolume" decimal,
  "dateDeleted" timestamp,
  "levelNew" int,
  "consultantPersonName" varchar,
  "commissionsToReduceAmount" int,
  "date" timestamp,
  "groupVolumeCumulative" decimal,
  "gap" boolean,
  "gapValuePercentage" decimal,
  "levelPrevious" int,
  "groupVolume" decimal,
  "createdAt" timestamp,
  "nominalLevel" int,
  "firstLineBranches" int,
  "calculationLevel" int,
  "changedAt" timestamp,
  "gapValue" decimal
);

CREATE TABLE "qualificationSavingTrigger" (
  "id" int PRIMARY KEY,
  "headers" varchar,
  "urlData" varchar,
  "user_field" int,
  "date" timestamp
);

CREATE TABLE "reportGenerator" (
  "id" int PRIMARY KEY,
  "createdAt" timestamp,
  "monthlyReports" int,
  "qualificationLogs" int,
  "transactions" int,
  "commissions" int,
  "consultantBalances" int,
  "consultantPayments" int,
  "intermediateJson" json,
  "finalJson" json,
  "consultant" int
);

CREATE TABLE "reportLogs" (
  "id" int PRIMARY KEY,
  "error" varchar,
  "result" varchar,
  "date" timestamp
);

CREATE TABLE "requisites" (
  "id" int PRIMARY KEY,
  "consultant" int,
  "address" varchar,
  "individualEntrepreneur" varchar,
  "companyName" varchar,
  "ogrn" varchar,
  "inn" varchar,
  "registrationNumber" varchar,
  "registrationAuthority" varchar,
  "registrationDate" timestamp,
  "email" varchar,
  "phone" varchar,
  "patent" boolean,
  "uproshenka" varchar,
  "okved" varchar,
  "selfEmployed" boolean,
  "comment" varchar,
  "verified" boolean,
  "webUser" int,
  "urlData" varchar,
  "headers" varchar,
  "deletedAt" timestamp,
  "dateChange" timestamp,
  "status" int
);

CREATE TABLE "ResetPasswordRequest" (
  "id" int PRIMARY KEY,
  "login" int
);

CREATE TABLE "riskProfile" (
  "id" int PRIMARY KEY,
  "name" varchar
);

CREATE TABLE "roboadvisor" (
  "id" int PRIMARY KEY,
  "type" varchar,
  "basecommission" decimal,
  "client" varchar,
  "paymentdate" timestamp,
  "amount" decimal,
  "contract" varchar,
  "importTransactionLog" int,
  "typeIsEmpty" boolean,
  "basecommissionIsEmpty" boolean,
  "paymentdateIsEmpty" boolean,
  "contractSearch" boolean,
  "contractLink" int
);

CREATE TABLE "roles" (
  "id" int PRIMARY KEY,
  "title" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "setup" (
  "id" int PRIMARY KEY,
  "setup" varchar,
  "consultant" int
);

CREATE TABLE "SmsLog" (
  "id" int PRIMARY KEY,
  "networkID" int,
  "autopilotID" int,
  "phoneTo" varchar,
  "msg" varchar,
  "provider" varchar,
  "configurationID" int,
  "sid" varchar,
  "dateCreated" varchar,
  "autopilotUUID" varchar,
  "autopilotName" varchar,
  "status" varchar,
  "price" decimal,
  "priceOur" decimal,
  "isCounted" boolean,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "SocialUser" (
  "id" int PRIMARY KEY,
  "webUserId" int,
  "token" varchar,
  "typeSocial" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "status" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "status_contest" (
  "id" int PRIMARY KEY,
  "name" varchar
);

CREATE TABLE "status_levels" (
  "id" int PRIMARY KEY,
  "otrif" decimal,
  "status" int,
  "groupVolume" decimal,
  "level" int,
  "title" varchar,
  "pool" decimal,
  "groupVolumeCumulative" decimal,
  "percent" decimal,
  "comment" varchar
);

CREATE TABLE "status_requisites" (
  "id" int PRIMARY KEY,
  "level" int,
  "name" varchar
);

CREATE TABLE "statuses" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "levels" int
);

CREATE TABLE "statusImportTransaction" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "structure" (
  "id" int PRIMARY KEY,
  "title" varchar,
  "lead" int
);

CREATE TABLE "SystemException" (
  "id" int PRIMARY KEY,
  "msg" varchar,
  "pilotSysName" varchar,
  "stepID" int,
  "objectID" varchar,
  "scenarioName" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "SystemMessage" (
  "id" int PRIMARY KEY,
  "userID" int,
  "type" varchar,
  "msg" varchar,
  "isError" boolean
);

CREATE TABLE "TChat" (
  "id" int PRIMARY KEY,
  "lastMessageKey" int,
  "chatId" int,
  "botName" varchar,
  "type" varchar,
  "title" varchar,
  "firstName" varchar,
  "lastName" varchar,
  "userName" varchar,
  "context" varchar,
  "botId" varchar,
  "Id" int,
  "key_field" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "team" (
  "id" int PRIMARY KEY,
  "lead" int,
  "title" varchar,
  "structure" int
);

CREATE TABLE "temp_password_creation" (
  "id" int PRIMARY KEY,
  "person" int
);

CREATE TABLE "termContract" (
  "id" int PRIMARY KEY,
  "term" int
);

CREATE TABLE "test" (
  "id" int PRIMARY KEY,
  "name" varchar
);

CREATE TABLE "test_connection_between_cons_and_team" (
  "id" int PRIMARY KEY,
  "json" json,
  "field" varchar
);

CREATE TABLE "title" (
  "id" int PRIMARY KEY,
  "title" varchar
);

CREATE TABLE "TKeyboard" (
  "id" int PRIMARY KEY,
  "keyboard" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "TMessageIn" (
  "id" int PRIMARY KEY,
  "userId" int,
  "chatKey" int,
  "text" varchar,
  "botName" varchar,
  "date" varchar,
  "phone_number" varchar,
  "API_response" json,
  "longitude" decimal,
  "latitude" decimal,
  "fromId" int,
  "messageId" int,
  "botId" varchar,
  "fileUrl" varchar,
  "key_field" varchar,
  "photo_id" varchar,
  "file_id" varchar,
  "file_name" varchar,
  "file_type" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "TMessageOut" (
  "id" int PRIMARY KEY,
  "chatKey" int,
  "keyboardId" int,
  "botName" varchar,
  "date" varchar,
  "text" varchar,
  "layoutText" varchar,
  "botId" varchar,
  "key_field" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "transaction" (
  "id" int PRIMARY KEY,
  "contract" int,
  "comment" varchar,
  "techComment" varchar,
  "amount" decimal,
  "amountAfterFee" decimal,
  "amountRUB" decimal,
  "amountUSD" decimal,
  "commissionsAmount" decimal,
  "commissionsAmountRUB" decimal,
  "commissionsAmountUSD" decimal,
  "profit" decimal,
  "profitRUB" decimal,
  "profitUSD" decimal,
  "score" varchar,
  "customCommission" boolean,
  "zeroDsIncome" boolean,
  "personalVolume" decimal,
  "groupVolume" decimal,
  "commissionCalcProperty" int,
  "commissions" int,
  "dsCommissionPercentage" decimal,
  "dsCommissionAbsolute" decimal,
  "nocomission" int,
  "vat" int,
  "userChanged" int,
  "getCourseOrderWebHookData" int,
  "partnersInChain" text,
  "urlData" varchar,
  "headers" varchar,
  "GetCourseexporttransactionsdata" int,
  "date" timestamp,
  "dateCreated" timestamp,
  "deletedAt" timestamp,
  "dateDay" timestamp,
  "dateMonth" varchar,
  "dateYear" varchar,
  "changedAt" timestamp,
  "comission" decimal,
  "comissionRUB" decimal,
  "comissionUSD" decimal,
  "netRevenue" decimal,
  "netRevenueRUB" decimal,
  "netRevenueUSD" decimal,
  "currency" int,
  "currencyRate" decimal,
  "customCurrencyRate" boolean,
  "usdRate" decimal,
  "bubbleId" varchar,
  "bubbleApiPatchResponse" json,
  "bubbleApiPostCommissionsResponse" json,
  "commissionAmountRubBeforeGapReduction" decimal,
  "profitRubBeforeGapReduction" decimal,
  "withheldTotalAmount" decimal,
  "withheldTotalAmountRUB" decimal,
  "withheldTotalAmountUSD" decimal,
  "withheldGapAmount" decimal,
  "withheldCombinedAmount" decimal,
  "withheldCombinedAmountRUB" decimal,
  "withheldCombinedAmountUSD" decimal,
  "withheldTotalPercent" decimal
);

CREATE TABLE "transactionDeleting" (
  "id" int PRIMARY KEY,
  "urlData" json,
  "user_field" int,
  "transactions" int,
  "headers" json
);

CREATE TABLE "transactionRecalculation" (
  "id" int PRIMARY KEY,
  "transactions" int,
  "user_field" int,
  "urlData" varchar,
  "headers" varchar
);

CREATE TABLE "triggerCron" (
  "id" int PRIMARY KEY
);

CREATE TABLE "TUser" (
  "id" int PRIMARY KEY,
  "lastChatKey" int,
  "lastMessageKey" int,
  "botName" varchar,
  "firstName" varchar,
  "lastName" varchar,
  "userName" varchar,
  "botId" varchar,
  "Id" varchar,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "type_contest" (
  "id" int PRIMARY KEY,
  "type" varchar
);

CREATE TABLE "type_criterion" (
  "id" int PRIMARY KEY,
  "name" varchar
);

CREATE TABLE "typeMailConsultantStatus" (
  "id" int PRIMARY KEY,
  "type" varchar
);

CREATE TABLE "unactualBalances" (
  "id" int PRIMARY KEY,
  "date" timestamp,
  "consultantBalance" int,
  "balance" decimal,
  "name" varchar,
  "remainingPrevious" decimal
);

CREATE TABLE "unactualQlogs" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "date" timestamp,
  "qLog" int,
  "gvc" decimal,
  "gvcPrevious" decimal
);

CREATE TABLE "unilife" (
  "id" int PRIMARY KEY,
  "paymentdate" timestamp,
  "contract" varchar,
  "contractLink" int,
  "contractIsEmpty" boolean,
  "year" varchar,
  "clientEn" varchar,
  "amountTransaction" decimal,
  "amountIsEmpty" boolean,
  "yearIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "currencyrIsEmpty" boolean,
  "client" varchar,
  "currency" varchar,
  "yearnumber" int,
  "amount" decimal,
  "importTransactionLog" int
);

CREATE TABLE "user_status_log" (
  "id" int PRIMARY KEY,
  "user_field" int,
  "status" int,
  "status_lvl" int,
  "datetime_change" timestamp,
  "who_change" int,
  "response" varchar
);

CREATE TABLE "usergroups" (
  "id" int PRIMARY KEY,
  "name" varchar,
  "comment" varchar
);

CREATE TABLE "vat" (
  "id" int PRIMARY KEY,
  "value" decimal,
  "dateFrom" timestamp,
  "dateTo" timestamp
);

CREATE TABLE "vatChangesTrigger" (
  "id" int PRIMARY KEY,
  "dateFrom" timestamp,
  "dateTo" timestamp,
  "value" decimal,
  "createdAt" timestamp,
  "urlData" json,
  "headers" json,
  "user_field" int
);

CREATE TABLE "volumeCalculator" (
  "id" int PRIMARY KEY,
  "user_field" int,
  "qulaification" int,
  "productType" int,
  "product" int,
  "program" int,
  "calcProperty" int,
  "currency" int,
  "curencyRate" int,
  "amountRub" decimal,
  "amount" decimal,
  "vat" int,
  "termContract" int,
  "comment" varchar,
  "dsCommission" int,
  "dsCommissionPercentage" decimal,
  "dsIncome" decimal,
  "dsIncomeRub" decimal,
  "groupBonus" decimal,
  "groupBonusRub" decimal,
  "peronalVolume" decimal,
  "createdAt" timestamp,
  "WebUser" int,
  "headers" varchar,
  "urlData" varchar
);

CREATE TABLE "volumeCalculatorHistoryCleaner" (
  "id" int PRIMARY KEY,
  "urlData" varchar,
  "headers" varchar,
  "user_field" int
);

CREATE TABLE "WebFlowAccess" (
  "id" int PRIMARY KEY,
  "page" varchar,
  "roles" text,
  "_who" varchar,
  "_dateCreated" timestamp,
  "_dateChanged" timestamp
);

CREATE TABLE "webHookInsmartError" (
  "id" int PRIMARY KEY,
  "webHookOrder" int,
  "error" varchar
);

CREATE TABLE "WebUser" (
  "test" boolean,
  "comment" varchar,
  "phone" varchar,
  "email" varchar,
  "userpic" varchar,
  "nicTG" varchar,
  "emailOld" varchar,
  "role" text,
  "id" int PRIMARY KEY,
  "password" varchar,
  "lastName" varchar,
  "firstName" varchar,
  "patronymic" varchar,
  "gender" varchar,
  "birthDate" timestamp,
  "taxResidency" int,
  "city" int,
  "dateCreated" varchar,
  "dateDeleted" timestamp,
  "dateLastActivity" varchar,
  "dateChanged" timestamp,
  "boughtProRost" boolean,
  "getCourseRegistrationWebHookData" int,
  "getCourseUserId" varchar,
  "getCourseUserIdarray" text,
  "getCourseOrderWebHookData" int,
  "urlData" varchar,
  "headers" varchar,
  "webUser" int,
  "status" int,
  "client" int,
  "consultant_id" int,
  "agreement" boolean,
  "isAuthorization" boolean,
  "isBlocked" boolean
);

CREATE TABLE "WebUserSession" (
  "isExpired" boolean,
  "dateLastRequest" varchar,
  "dateCreated" varchar,
  "webUserId" int,
  "sessionId" int
);

CREATE TABLE "woodville" (
  "id" int PRIMARY KEY,
  "paymentdate" timestamp,
  "contract" varchar,
  "contractLink" int,
  "currency" varchar,
  "program" varchar,
  "contractIsEmpty" boolean,
  "amountIsEmpty" boolean,
  "paymentIsEmpty" boolean,
  "amountrub" decimal,
  "client" varchar,
  "amount" decimal,
  "importTransactionLog" int,
  "comission" decimal
);

CREATE TABLE "ZapierHook" (
  "settings" varchar,
  "active" boolean,
  "data_source" varchar,
  "url" varchar,
  "id" int PRIMARY KEY
);

-- Foreign Keys (non-report tables only)

ALTER TABLE "transactionDeleting" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactionDeleting" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactionRecalculation" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactionRecalculation" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("commissionCalcProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("commissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("nocomission") REFERENCES "nocomission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("vat") REFERENCES "vat" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("userChanged") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("getCourseOrderWebHookData") REFERENCES "getCourseOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("GetCourseexporttransactionsdata") REFERENCES "getcourseExportTransactionsData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("commissionFromOtherConsultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("consultantsChain") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commission" ADD FOREIGN KEY ("calculationLevel") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importTransactionLog" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importTransactionLog" ADD FOREIGN KEY ("ibCounter") REFERENCES "ibCounter" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importTransactionLog" ADD FOREIGN KEY ("status") REFERENCES "statusImportTransaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importTransactionLog" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "unilife" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "unilife" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "investorsTrust" ADD FOREIGN KEY ("dsCommissionsArray") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "investorsTrust" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "investorsTrust" ADD FOREIGN KEY ("newDsCommision") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "investorsTrust" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "investorsTrust" ADD FOREIGN KEY ("oldDsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "woodville" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "woodville" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "privateEquity" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "privateEquity" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeContractDsCommisionTrigger" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeContractDsCommisionTrigger" ADD FOREIGN KEY ("dsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeContractDsCommisionTrigger" ADD FOREIGN KEY ("dsCommissionToRemove") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeContractDsCommisionTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "broker" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "broker" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "createImportTransaction" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "createImportTransaction" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("importTransactionLogFront") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("roboDate") REFERENCES "roboadvisor" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("roboType") REFERENCES "roboadvisor" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("roboBase") REFERENCES "roboadvisor" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("roboContract") REFERENCES "roboadvisor" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "importtransactionfromn8n" ADD FOREIGN KEY ("robo") REFERENCES "roboadvisor" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "bkc" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "bkc" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "gga" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "gga" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "roboadvisor" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "roboadvisor" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "anderida" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "anderida" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "ibCounter" ADD FOREIGN KEY ("contractLink") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "ibCounter" ADD FOREIGN KEY ("importTransactionLog") REFERENCES "importTransactionLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "ibCounter" ADD FOREIGN KEY ("commissionProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("transactionsWithFees") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("RemovalCommissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("transactionsWithUnknownPartner") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("transactionsWithKnownPartner") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("consultantsWithCommissions") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("qualificationLogs") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("consultantBalances") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("transactionsWithoutFees") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "massTransactionRecalculationTrigger" ADD FOREIGN KEY ("customCommissionTransactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "vatChangesTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "currencyRatesChangesTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "city" ADD FOREIGN KEY ("country") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "setup" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "profitability" ADD FOREIGN KEY ("riskProfile") REFERENCES "riskProfile" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "profitability" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "statuses" ADD FOREIGN KEY ("levels") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "currencyRate" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "status_levels" ADD FOREIGN KEY ("status") REFERENCES "statuses" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "pattern" ADD FOREIGN KEY ("idCounterparty") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "platformCommunication" ADD FOREIGN KEY ("author") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "platformCommunication" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "platformCommunication" ADD FOREIGN KEY ("category") REFERENCES "communicationCategory" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "platformCommunication" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("coefficientPersonalVolume") REFERENCES "coefficientCriterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("coefficientGroupVolume") REFERENCES "coefficientCriterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("coefficientGroupVolumeCumulative") REFERENCES "coefficientCriterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("contracts") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("qualification") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantPoints" ADD FOREIGN KEY ("commissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "coefficientCriterion" ADD FOREIGN KEY ("levelQualification") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "coefficientCriterion" ADD FOREIGN KEY ("criterion") REFERENCES "criterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "coefficientCriterion" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("type") REFERENCES "type_contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("status") REFERENCES "status_contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("banner") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "Contest" ADD FOREIGN KEY ("criterion") REFERENCES "criterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contestrating" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contestrating" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantRaiting" ADD FOREIGN KEY ("arrayPoints") REFERENCES "contestrating" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationConsultantRaiting" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("commissionsProduct") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("contracts") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("commissions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("qualifications") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("qualificationLogActive") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "calculationContestTrigger" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("contest") REFERENCES "Contest" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("type") REFERENCES "type_criterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("qualificationValue") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("coefficientDelete") REFERENCES "coefficientCriterion" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "criterion" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("consultantBalance") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("qualifications_old") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("qualifications") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("commissions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("revenueProduct") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("revenueRevenue") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("revenueExpenses") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("comissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("comissions2") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("comissions3") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("consultantBalance2") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("consultantPAyments") REFERENCES "consultantPayment" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "documentlogs" ADD FOREIGN KEY ("resultFile") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "nocomission" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "nocomission" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("programsArray") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("consultantsChain") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("setup") REFERENCES "setup" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("changeConsultantContractLog") REFERENCES "changeConsultantContractLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("country") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("getCourseOrderWebHookData") REFERENCES "getCourseOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("status") REFERENCES "contractStatus" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("dsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("consultantCountry") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("consultantsChainLevels") REFERENCES "consultantMotivationGroupLevel" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("riskProfile") REFERENCES "riskProfile" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("getInsmartOrderWebHookData") REFERENCES "getInsmartOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "contract" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantsWithMissingLogs" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantsWithMissingLogs" ADD FOREIGN KEY ("firstLogBeforeMissingOne") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationSavingTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "unactualQlogs" ADD FOREIGN KEY ("qLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("commissionsToReduce") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("branchWithGap") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("qualificationLogPrevious") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("levelNew") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("levelPrevious") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("nominalLevel") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("firstLineBranches") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationLog" ADD FOREIGN KEY ("calculationLevel") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationCalculationsTrigger" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "qualificationCalculationsTrigger" ADD FOREIGN KEY ("triggeredBy") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "poolTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "poolTrigger" ADD FOREIGN KEY ("consultants") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "poolLog" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "poolLog" ADD FOREIGN KEY ("networkGroupBonus") REFERENCES "networkGroupBonus" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "networkGroupBonus" ADD FOREIGN KEY ("consultants") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "firstBalances" ADD FOREIGN KEY ("balance") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "firstBalances" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "firstBalances" ADD FOREIGN KEY ("qLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("partnerMonthlyPaymentsReport") REFERENCES "partnerMonthlyPaymentsReportTrigger" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("consultantPayments") REFERENCES "consultantPayment" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantBalance" ADD FOREIGN KEY ("groupSalesTransactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantPayment" ADD FOREIGN KEY ("consultantBalance") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantPayment" ADD FOREIGN KEY ("status") REFERENCES "consultantPaymentStatus" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantPayment" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "unactualBalances" ADD FOREIGN KEY ("consultantBalance") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "SocialUser" ADD FOREIGN KEY ("webUserId") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "ResetPasswordRequest" ADD FOREIGN KEY ("login") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "cronPartnerCompressionDaily" ADD FOREIGN KEY ("monthlyReportAvailability") REFERENCES "monthlyReportAvailabilityIndicator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "cronPartnerCompressionDaily" ADD FOREIGN KEY ("consultans") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "cronPartnerCompressionDaily" ADD FOREIGN KEY ("monthlyReportAvailabilityPrevious") REFERENCES "monthlyReportAvailabilityIndicator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUserSession" ADD FOREIGN KEY ("webUserId") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "logAcceptance" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "logAcceptance" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "chageConsultanStatusLog" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "chageConsultanStatusLog" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("applicationForPayment") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("passportScanPage1") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("commissionLast") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("person") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("title") REFERENCES "title" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("statusRequisites") REFERENCES "status_requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("soldProducts") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("status_and_lvl") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("qualificationLog") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("clients") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("transactionProrostId") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("passportScanPage2") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("ambassadorForProducts") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("changeConsultantInvitertLog") REFERENCES "changeConsultantInviterLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("inviter") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("soldPrograms") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("upperLevels") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("contracts") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("requisites") REFERENCES "requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("bankRequisitesForPayments") REFERENCES "bankrequisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("tempRequisites") REFERENCES "requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("structure") REFERENCES "structure" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("team") REFERENCES "team" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("country") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("invited") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("activity") REFERENCES "directory_of_activities" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("status") REFERENCES "status" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("transactionSpfkId") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultant" ADD FOREIGN KEY ("agreementlink") REFERENCES "partnerAcceptance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "notification" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "notification" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_status_log" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_status_log" ADD FOREIGN KEY ("status") REFERENCES "statuses" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_status_log" ADD FOREIGN KEY ("status_lvl") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_status_log" ADD FOREIGN KEY ("who_change") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantProgramsData" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantProgramsData" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantProgramsData" ADD FOREIGN KEY ("productType") REFERENCES "productType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantProgramsData" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantProgramsData" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "bankrequisites" ADD FOREIGN KEY ("status") REFERENCES "status_requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "bankrequisites" ADD FOREIGN KEY ("requisites") REFERENCES "requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "bankrequisites" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantStatusChangeMailing" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantStatusChangeMailing" ADD FOREIGN KEY ("type") REFERENCES "typeMailConsultantStatus" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("allConsultants") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level7") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level4") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level6") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level3") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level5") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level8") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level10") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level2") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level9") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantStructureTrigger" ADD FOREIGN KEY ("level1") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "structure" ADD FOREIGN KEY ("lead") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerAcceptance" ADD FOREIGN KEY ("logAccepted") REFERENCES "logAcceptance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerAcceptance" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerAcceptance" ADD FOREIGN KEY ("documentType") REFERENCES "agreementPartnersDocuments" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerAcceptance" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantStructure" ADD FOREIGN KEY ("child") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantStructure" ADD FOREIGN KEY ("parent") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "requisites" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "requisites" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "requisites" ADD FOREIGN KEY ("status") REFERENCES "status_requisites" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantMotivationGroupLevel" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "consultantMotivationGroupLevel" ADD FOREIGN KEY ("level") REFERENCES "motivationGroupLevel" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "temp_password_creation" ADD FOREIGN KEY ("person") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "team" ADD FOREIGN KEY ("lead") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "team" ADD FOREIGN KEY ("structure") REFERENCES "structure" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("monthlyReportAvailabilityIndicator") REFERENCES "monthlyReportAvailabilityIndicator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("invited") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dataPermutationTrigger" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantInviterLog" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantInviterLog" ADD FOREIGN KEY ("inviterNew") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantInviterLog" ADD FOREIGN KEY ("inviterOld") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantInviterLog" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantClientLog" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantClientLog" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantClientLog" ADD FOREIGN KEY ("consultantOld") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantClientLog" ADD FOREIGN KEY ("consultantNew") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantContractLog" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantContractLog" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantContractLog" ADD FOREIGN KEY ("consultantOld") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "changeConsultantContractLog" ADD FOREIGN KEY ("consultantNew") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("taxResidency") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("city") REFERENCES "city" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("getCourseRegistrationWebHookData") REFERENCES "getCourseRegistrationWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("getCourseOrderWebHookData") REFERENCES "getCourseOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("status") REFERENCES "statuses" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "WebUser" ADD FOREIGN KEY ("consultant_id") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "backofficeregistration" ADD FOREIGN KEY ("roles") REFERENCES "roles" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "commissionsReport" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportMailing" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportMailing" ADD FOREIGN KEY ("consultants") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthlyReportAvailabilityIndicator" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("monthlyReports") REFERENCES "monthlyReports" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("qualificationLogs") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("commissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("consultantBalances") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("consultantPayments") REFERENCES "consultantPayment" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "reportGenerator" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthlyReports" ADD FOREIGN KEY ("reportGenerator") REFERENCES "reportGenerator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthlyReports" ADD FOREIGN KEY ("file") REFERENCES "FileUpload" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthlyReports" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthlyReports" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("commissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("commissionsGroup") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("commissionsPersonal") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("consultantPayments") REFERENCES "consultantPayment" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("pool") REFERENCES "poolLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("consultantBalance") REFERENCES "consultantBalance" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "partnerMonthlyPaymentsReportTrigger" ADD FOREIGN KEY ("nonTransactionalCommissions") REFERENCES "commission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "lastN8nSyncTimestam" ADD FOREIGN KEY ("contract") REFERENCES "exportLogContract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "lastN8nSyncTimestam" ADD FOREIGN KEY ("transaction") REFERENCES "exportLogTransactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "lastN8nSyncTimestam" ADD FOREIGN KEY ("client") REFERENCES "exportLogClients" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "lastN8nSyncTimestam" ADD FOREIGN KEY ("consultant") REFERENCES "exportLogConsultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "lastN8nSyncTimestam" ADD FOREIGN KEY ("qLog") REFERENCES "exportLogQualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogTransactions" ADD FOREIGN KEY ("lastN8nSync") REFERENCES "lastN8nSyncTimestam" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogTransactions" ADD FOREIGN KEY ("transactions") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogContract" ADD FOREIGN KEY ("lastN8nSync") REFERENCES "lastN8nSyncTimestam" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogContract" ADD FOREIGN KEY ("contracts") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogClients" ADD FOREIGN KEY ("lastN8nSync") REFERENCES "lastN8nSyncTimestam" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogClients" ADD FOREIGN KEY ("clients") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogQualificationLog" ADD FOREIGN KEY ("lastN8nSync") REFERENCES "lastN8nSyncTimestam" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogQualificationLog" ADD FOREIGN KEY ("qLogs") REFERENCES "qualificationLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogConsultant" ADD FOREIGN KEY ("lastN8nSync") REFERENCES "lastN8nSyncTimestam" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "exportLogConsultant" ADD FOREIGN KEY ("consultants") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseLog" ADD FOREIGN KEY ("webHookRegistration") REFERENCES "getCourseRegistrationWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseLog" ADD FOREIGN KEY ("webHookOrder") REFERENCES "getCourseOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseLog" ADD FOREIGN KEY ("getcourseExportTransactionsData") REFERENCES "getcourseExportTransactionsData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseRegistrationWebHookData" ADD FOREIGN KEY ("person") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseCreateResidentPromocodeDebit" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseCreateResidentPromocodeDebit" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseOrderWebHookData" ADD FOREIGN KEY ("persona") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseOrderWebHookData" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseOrderWebHookData" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseOrderWebHookData" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseOrderWebHookData" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getCourseTransactionsFromGoogleSpreadsheetsWebHookData" ADD FOREIGN KEY ("getcourseExportTransactionsData") REFERENCES "getcourseExportTransactionsData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseExportTransactionsData" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseExportTransactionsData" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseExportTransactionsData" ADD FOREIGN KEY ("contractDeleted") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("vender") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("dsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("productNewInsmart") REFERENCES "insmartProduct" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getInsmartOrderWebHookData" ADD FOREIGN KEY ("venderNewInsmart") REFERENCES "insmartVender" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "webHookInsmartError" ADD FOREIGN KEY ("webHookOrder") REFERENCES "getInsmartOrderWebHookData" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "cbrResponse" ADD FOREIGN KEY ("currencyRates") REFERENCES "currencyRates" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "cbrResponse" ADD FOREIGN KEY ("currencyDictionary") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoalsTrigger" ADD FOREIGN KEY ("riskProfile") REFERENCES "riskProfile" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoalsTrigger" ADD FOREIGN KEY ("goals") REFERENCES "clientGoal" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientFamily" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientFamily" ADD FOREIGN KEY ("occupation") REFERENCES "occupation" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "assetsHistory" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "assetsHistory" ADD FOREIGN KEY ("asset") REFERENCES "clientsCapital" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoal" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoal" ADD FOREIGN KEY ("averageInflationInUsd") REFERENCES "calculationsConstant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoal" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoal" ADD FOREIGN KEY ("riskProfile") REFERENCES "riskProfile" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientGoal" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsIndicators" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsIndicators" ADD FOREIGN KEY ("indicator") REFERENCES "indicator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsIndicators" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "meeting" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "meeting" ADD FOREIGN KEY ("type") REFERENCES "meetingType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "meeting" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("consultant") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("family") REFERENCES "clientFamily" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("assetsArray") REFERENCES "clientsCapital" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("goalsArray") REFERENCES "clientGoal" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("products") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("occupation") REFERENCES "occupation" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("indicators") REFERENCES "clientsIndicators" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("webUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("contracts") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("citizenship") REFERENCES "country" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("person") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "client" ADD FOREIGN KEY ("changeConsultantClientLog") REFERENCES "changeConsultantClientLog" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsCapital" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsCapital" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "clientsCapital" ADD FOREIGN KEY ("city") REFERENCES "city" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "indicatorsHistory" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "indicatorsHistory" ADD FOREIGN KEY ("client") REFERENCES "client" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "indicatorsHistory" ADD FOREIGN KEY ("indicator") REFERENCES "indicator" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculatorHistoryCleaner" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("user_field") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("qulaification") REFERENCES "status_levels" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("productType") REFERENCES "productType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("calcProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("curencyRate") REFERENCES "currencyRate" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("vat") REFERENCES "vat" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("dsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "volumeCalculator" ADD FOREIGN KEY ("WebUser") REFERENCES "WebUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("vendor") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("provider") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("dsCommission") REFERENCES "dsCommission" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("termContract") REFERENCES "termContract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("currency") REFERENCES "currency" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("commissionCalcProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("category") REFERENCES "productCategory" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "program" ADD FOREIGN KEY ("productType") REFERENCES "productType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "motivationGroupLevel" ADD FOREIGN KEY ("motivationGroup") REFERENCES "motivationGroup" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dsCommission" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dsCommission" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "dsCommission" ADD FOREIGN KEY ("commissionCalcProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "motivationGroup" ADD FOREIGN KEY ("products") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productType" ADD FOREIGN KEY ("productTypeCategory") REFERENCES "productCategory" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "product" ADD FOREIGN KEY ("motivationGroup") REFERENCES "motivationGroup" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "product" ADD FOREIGN KEY ("ambassador") REFERENCES "consultant" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "product" ADD FOREIGN KEY ("access") REFERENCES "statuses" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "product" ADD FOREIGN KEY ("productType") REFERENCES "productType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "product" ADD FOREIGN KEY ("productTags") REFERENCES "productTags" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "comissionByLevel" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "comissionByLevel" ADD FOREIGN KEY ("level") REFERENCES "motivationGroupLevel" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "comissionByLevel" ADD FOREIGN KEY ("commissionCalcProperty") REFERENCES "commissionCalcProperty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("vendor") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("productType") REFERENCES "productType" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("program") REFERENCES "program" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("provider") REFERENCES "counterparty" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("productCategory") REFERENCES "productCategory" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "productMatrix" ADD FOREIGN KEY ("product") REFERENCES "product" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseTransactionExportDataFromGoogleSpreadsheet" ADD FOREIGN KEY ("contract") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseTransactionExportDataFromGoogleSpreadsheet" ADD FOREIGN KEY ("transaction") REFERENCES "transaction" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "getcourseTransactionExportDataFromGoogleSpreadsheet" ADD FOREIGN KEY ("contractDeleted") REFERENCES "contract" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "NearTransaction" ADD FOREIGN KEY ("receiver_account_id") REFERENCES "CryptoWallet" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "CryptoTransaction" ADD FOREIGN KEY ("to_field") REFERENCES "CryptoWallet" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TMessageOut" ADD FOREIGN KEY ("chatKey") REFERENCES "TChat" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TMessageOut" ADD FOREIGN KEY ("keyboardId") REFERENCES "TKeyboard" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TMessageIn" ADD FOREIGN KEY ("userId") REFERENCES "TUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TMessageIn" ADD FOREIGN KEY ("chatKey") REFERENCES "TChat" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TMessageIn" ADD FOREIGN KEY ("fromId") REFERENCES "TUser" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TUser" ADD FOREIGN KEY ("lastChatKey") REFERENCES "TChat" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TUser" ADD FOREIGN KEY ("lastMessageKey") REFERENCES "TMessageIn" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "TChat" ADD FOREIGN KEY ("lastMessageKey") REFERENCES "TMessageIn" ("id") DEFERRABLE INITIALLY IMMEDIATE;
