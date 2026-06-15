namespace IdentityReconciliation.Application.DTOs
{
    public record MoodleUserDto(
        int Id,
        string? Username,
        string? Email,
        string? Firstname,
        string? Lastname,
        string? Auth
    );

    public record GibbonPersonDto(
        int GibbonPersonId,
        string? Email,
        string? Username,
        string? OfficialName
    );

    public record ReconciliationReport(
        int LinkedCount,
        int ConflictCount,
        int MoodleOnly,
        int GibbonOnly,
        List<ConflictDetail> Conflicts
    );

    public record ConflictDetail(
        string? Email,
        List<MoodleUserDto> MoodleCandidates,
        List<GibbonPersonDto> GibbonCandidates
    );
}
