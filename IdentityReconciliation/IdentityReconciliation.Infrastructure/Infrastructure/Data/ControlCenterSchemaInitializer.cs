using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Logging;

namespace IdentityReconciliation.Infrastructure.Data;

/// <summary>
/// Transitional, idempotent schema bootstrap for installations created before
/// the Control Center existed. New installations are created from the EF model;
/// existing installations receive only the additive tables and user columns.
/// </summary>
public sealed class ControlCenterSchemaInitializer
{
    private readonly AppDbContext _db;
    private readonly ILogger<ControlCenterSchemaInitializer> _logger;

    public ControlCenterSchemaInitializer(AppDbContext db, ILogger<ControlCenterSchemaInitializer> logger)
    {
        _db = db;
        _logger = logger;
    }

    public async Task EnsureAsync(CancellationToken cancellationToken = default)
    {
        const string sql = """
IF COL_LENGTH('AspNetUsers', 'MustChangePassword') IS NULL ALTER TABLE AspNetUsers ADD MustChangePassword bit NOT NULL CONSTRAINT DF_AspNetUsers_MustChangePassword DEFAULT 0;
IF COL_LENGTH('AspNetUsers', 'MigrationStatus') IS NULL ALTER TABLE AspNetUsers ADD MigrationStatus int NOT NULL CONSTRAINT DF_AspNetUsers_MigrationStatus DEFAULT 0;
IF COL_LENGTH('AspNetUsers', 'MigrationSource') IS NULL ALTER TABLE AspNetUsers ADD MigrationSource nvarchar(100) NULL;
IF COL_LENGTH('AspNetUsers', 'MigratedAt') IS NULL ALTER TABLE AspNetUsers ADD MigratedAt datetime2 NULL;
IF COL_LENGTH('AspNetUsers', 'LastPasswordChangedAt') IS NULL ALTER TABLE AspNetUsers ADD LastPasswordChangedAt datetime2 NULL;
IF COL_LENGTH('AspNetUsers', 'LastSuccessfulLoginAt') IS NULL ALTER TABLE AspNetUsers ADD LastSuccessfulLoginAt datetime2 NULL;
IF COL_LENGTH('AspNetUsers', 'LastFailedLoginAt') IS NULL ALTER TABLE AspNetUsers ADD LastFailedLoginAt datetime2 NULL;
IF COL_LENGTH('AspNetUsers', 'IsProvisioningBlocked') IS NULL ALTER TABLE AspNetUsers ADD IsProvisioningBlocked bit NOT NULL CONSTRAINT DF_AspNetUsers_IsProvisioningBlocked DEFAULT 0;
IF COL_LENGTH('AspNetUsers', 'PreferredLanguage') IS NULL ALTER TABLE AspNetUsers ADD PreferredLanguage nvarchar(20) NOT NULL CONSTRAINT DF_AspNetUsers_PreferredLanguage DEFAULT 'en';

IF OBJECT_ID('IntegrationSystems', 'U') IS NULL
CREATE TABLE IntegrationSystems (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    Name nvarchar(150) NOT NULL,
    Type int NOT NULL,
    BaseUrl nvarchar(500) NOT NULL,
    IsEnabled bit NOT NULL,
    ProvisioningMode int NOT NULL,
    AllowAccountCreation bit NOT NULL,
    AllowFirstLoginClaim bit NOT NULL,
    EncryptedConfiguration nvarchar(max) NULL,
    LastHealthCheckAt datetime2 NULL,
    LastHealthCheckSucceeded bit NULL,
    LastHealthCheckMessage nvarchar(max) NULL,
    CreatedAt datetime2 NOT NULL,
    UpdatedAt datetime2 NOT NULL,
    CONSTRAINT UX_IntegrationSystems_Type_Name UNIQUE(Type, Name)
);

IF OBJECT_ID('ControlCenterSettings', 'U') IS NULL
CREATE TABLE ControlCenterSettings (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    Section nvarchar(100) NOT NULL,
    [Key] nvarchar(150) NOT NULL,
    Value nvarchar(max) NULL,
    IsSecret bit NOT NULL,
    UpdatedAt datetime2 NOT NULL,
    UpdatedBy nvarchar(256) NULL,
    CONSTRAINT UX_ControlCenterSettings_Section_Key UNIQUE(Section, [Key])
);

IF OBJECT_ID('SyncJobs', 'U') IS NULL
CREATE TABLE SyncJobs (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    JobType nvarchar(100) NOT NULL,
    Direction nvarchar(50) NOT NULL,
    [Trigger] nvarchar(50) NOT NULL,
    Status int NOT NULL,
    RequestedBy nvarchar(256) NULL,
    CorrelationId nvarchar(64) NOT NULL UNIQUE,
    PolicySnapshot nvarchar(max) NULL,
    TotalOperations int NOT NULL,
    SucceededOperations int NOT NULL,
    FailedOperations int NOT NULL,
    SkippedOperations int NOT NULL,
    CreatedAt datetime2 NOT NULL,
    StartedAt datetime2 NULL,
    CompletedAt datetime2 NULL
);

IF OBJECT_ID('SyncOperations', 'U') IS NULL
CREATE TABLE SyncOperations (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    SyncJobId uniqueidentifier NOT NULL,
    EntityType nvarchar(100) NOT NULL,
    Action nvarchar(100) NOT NULL,
    SourceSystem nvarchar(50) NOT NULL,
    SourceId nvarchar(150) NULL,
    TargetSystem nvarchar(50) NOT NULL,
    TargetId nvarchar(150) NULL,
    IdempotencyKey nvarchar(256) NOT NULL UNIQUE,
    Status int NOT NULL,
    BeforeSnapshot nvarchar(max) NULL,
    IntendedChange nvarchar(max) NULL,
    AfterSnapshot nvarchar(max) NULL,
    AttemptCount int NOT NULL,
    NextRetryAt datetime2 NULL,
    ErrorCategory nvarchar(100) NULL,
    ErrorMessage nvarchar(max) NULL,
    CreatedAt datetime2 NOT NULL,
    StartedAt datetime2 NULL,
    CompletedAt datetime2 NULL,
    CONSTRAINT FK_SyncOperations_SyncJobs FOREIGN KEY(SyncJobId) REFERENCES SyncJobs(Id) ON DELETE CASCADE
);

IF OBJECT_ID('SyncAttempts', 'U') IS NULL
CREATE TABLE SyncAttempts (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    SyncOperationId uniqueidentifier NOT NULL,
    AttemptNumber int NOT NULL,
    ResponseStatusCode int NULL,
    DurationMilliseconds bigint NOT NULL,
    Succeeded bit NOT NULL,
    ErrorCategory nvarchar(100) NULL,
    SanitizedMessage nvarchar(max) NULL,
    CreatedAt datetime2 NOT NULL,
    CONSTRAINT FK_SyncAttempts_SyncOperations FOREIGN KEY(SyncOperationId) REFERENCES SyncOperations(Id) ON DELETE CASCADE,
    CONSTRAINT UX_SyncAttempts_Operation_Attempt UNIQUE(SyncOperationId, AttemptNumber)
);

IF OBJECT_ID('AuditEvents', 'U') IS NULL
CREATE TABLE AuditEvents (
    Id uniqueidentifier NOT NULL PRIMARY KEY,
    Category nvarchar(100) NOT NULL,
    Action nvarchar(150) NOT NULL,
    ActorId nvarchar(256) NULL,
    SubjectType nvarchar(100) NULL,
    SubjectId nvarchar(150) NULL,
    CorrelationId nvarchar(64) NULL,
    Details nvarchar(max) NULL,
    IpAddress nvarchar(64) NULL,
    CreatedAt datetime2 NOT NULL
);
""";

        await _db.Database.ExecuteSqlRawAsync(sql, cancellationToken);
        _logger.LogInformation("Yulpana Control Center schema is ready.");
    }
}
