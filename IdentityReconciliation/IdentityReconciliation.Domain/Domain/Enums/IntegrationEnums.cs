namespace IdentityReconciliation.Domain.Enums;

public enum IntegrationSystemType
{
    Gibbon = 1,
    Moodle = 2
}

public enum ProvisioningMode
{
    ObserveOnly = 0,
    ApprovalRequired = 1,
    Automatic = 2
}

public enum SourceOfTruth
{
    None = 0,
    Gibbon = 1,
    Moodle = 2,
    IdentityProvider = 3
}

public enum SyncJobStatus
{
    Pending = 0,
    Running = 1,
    Succeeded = 2,
    PartiallySucceeded = 3,
    RetryScheduled = 4,
    NeedsReview = 5,
    Failed = 6,
    Cancelled = 7
}

public enum SyncOperationStatus
{
    Pending = 0,
    Running = 1,
    Succeeded = 2,
    RetryScheduled = 3,
    NeedsReview = 4,
    Failed = 5,
    Skipped = 6
}

public enum MigrationStatus
{
    NotStarted = 0,
    SourceVerified = 1,
    PasswordChangeRequired = 2,
    Completed = 3,
    Blocked = 4
}
