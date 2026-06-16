using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;
using IdentityReconciliation.Domain.Interfaces;
using Microsoft.Extensions.Logging;
using IdentityReconciliation.Application.Reconciliation.Commands;
using IdentityReconciliation.Application.Reconciliation.Queries;
using MediatR;

namespace IdentityReconciliation.Application.Reconciliation.Handlers
{
    public class ReconciliationCommandQueryHandler :
        IRequestHandler<RunReconciliationCommand, ReconciliationReport>,
        IRequestHandler<GetDashboardStatsQuery, DashboardStatsDto>,
        IRequestHandler<GetPendingMatchesQuery, List<PendingMatchDto>>,
        IRequestHandler<GetConflictsQuery, List<ConflictDto>>,
        IRequestHandler<GetSyncLogsQuery, List<SyncLogDto>>,
        IRequestHandler<AutoMatchCommand, AutoMatchResult>,
        IRequestHandler<ResolveConflictCommand>,
        IRequestHandler<LinkUsersCommand>,
        IRequestHandler<IgnoreMatchCommand>
    {
        private readonly IUserMapRepository _userMapRepository;
        private readonly IMoodleApiClient _moodleClient;
        private readonly IGibbonPersonRepository _gibbonRepo;
        private readonly ILogger<ReconciliationCommandQueryHandler> _logger;

        public ReconciliationCommandQueryHandler(
            IUserMapRepository userMapRepository,
            IMoodleApiClient moodleClient,
            IGibbonPersonRepository gibbonRepo,
            ILogger<ReconciliationCommandQueryHandler> logger)
        {
            _userMapRepository = userMapRepository;
            _moodleClient = moodleClient;
            _gibbonRepo = gibbonRepo;
            _logger = logger;
        }

        public async Task<ReconciliationReport> Handle(RunReconciliationCommand request, CancellationToken cancellationToken)
        {
            _logger.LogInformation("Starting identity reconciliation process");

            var moodleUsers = await _moodleClient.GetAllUsersAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();

            var processedGibbonIds = new HashSet<int>();
            var conflicts = new List<ConflictDetail>();
            int moodleOnlyCount = 0;

            foreach (var moodleUser in moodleUsers)
            {
                _logger.LogDebug("Processing Moodle user {MoodleId} ({Email})", moodleUser.Id, moodleUser.Email);

                List<GibbonPersonDto> emailMatches = new();
                List<GibbonPersonDto> usernameMatches = new();

                if (!string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    emailMatches = gibbonPersons
                        .Where(g => !string.IsNullOrWhiteSpace(g.Email) &&
                                    g.Email.Equals(moodleUser.Email, StringComparison.OrdinalIgnoreCase))
                        .ToList();
                }

                if (!string.IsNullOrWhiteSpace(moodleUser.Username))
                {
                    usernameMatches = gibbonPersons
                        .Where(g => !string.IsNullOrWhiteSpace(g.Username) &&
                                    g.Username.Equals(moodleUser.Username, StringComparison.OrdinalIgnoreCase))
                        .ToList();
                }

                if (emailMatches.Count == 1)
                {
                    var matchedGibbon = emailMatches[0];
                    processedGibbonIds.Add(matchedGibbon.GibbonPersonId);

                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        GibbonId = matchedGibbon.GibbonPersonId,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    userMap.MarkAsLinked(100);
                    await _userMapRepository.AddAsync(userMap);

                    _logger.LogInformation("Linked Moodle user {MoodleId} to Gibbon person {GibbonId} with 100% confidence",
                        moodleUser.Id, matchedGibbon.GibbonPersonId);
                }
                else if (emailMatches.Count > 1)
                {
                    foreach (var matchedGibbon in emailMatches)
                    {
                        var userMap = new UserMap
                        {
                            MoodleId = moodleUser.Id,
                            GibbonId = matchedGibbon.GibbonPersonId,
                            Email = moodleUser.Email,
                            Username = moodleUser.Username
                        };

                        userMap.MarkAsConflict();
                        await _userMapRepository.AddAsync(userMap);
                    }

                    conflicts.Add(new ConflictDetail(
                        moodleUser.Email,
                        new List<MoodleUserDto> { moodleUser },
                        emailMatches));

                    _logger.LogWarning("Conflict detected for Moodle user {MoodleId} with {Count} Gibbon matches",
                        moodleUser.Id, emailMatches.Count);
                }
                else if (usernameMatches.Count == 1 && string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    var matchedGibbon = usernameMatches[0];
                    processedGibbonIds.Add(matchedGibbon.GibbonPersonId);

                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        GibbonId = matchedGibbon.GibbonPersonId,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    userMap.MarkAsLinked(90);
                    await _userMapRepository.AddAsync(userMap);

                    _logger.LogInformation("Linked Moodle user {MoodleId} to Gibbon person {GibbonId} via username with 90% confidence",
                        moodleUser.Id, matchedGibbon.GibbonPersonId);
                }
                else if (usernameMatches.Count > 1 && string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    foreach (var matchedGibbon in usernameMatches)
                    {
                        var userMap = new UserMap
                        {
                            MoodleId = moodleUser.Id,
                            GibbonId = matchedGibbon.GibbonPersonId,
                            Email = moodleUser.Email,
                            Username = moodleUser.Username
                        };

                        userMap.MarkAsConflict();
                        await _userMapRepository.AddAsync(userMap);
                    }

                    conflicts.Add(new ConflictDetail(
                        moodleUser.Email,
                        new List<MoodleUserDto> { moodleUser },
                        usernameMatches));

                    _logger.LogWarning("Username conflict detected for Moodle user {MoodleId} with {Count} Gibbon matches",
                        moodleUser.Id, usernameMatches.Count);
                }
                else
                {
                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    await _userMapRepository.AddAsync(userMap);
                    moodleOnlyCount++;

                    _logger.LogDebug("No match found for Moodle user {MoodleId}", moodleUser.Id);
                }
            }

            int gibbonOnlyCount = gibbonPersons.Count(p => !processedGibbonIds.Contains(p.GibbonPersonId));

            await _userMapRepository.SaveChangesAsync();

            var linkedCount = moodleUsers.Count - moodleOnlyCount - conflicts.Sum(c => c.MoodleCandidates.Count);

            var report = new ReconciliationReport(
                LinkedCount: linkedCount,
                ConflictCount: conflicts.Count,
                MoodleOnly: moodleOnlyCount,
                GibbonOnly: gibbonOnlyCount,
                Conflicts: conflicts);

            _logger.LogInformation("Reconciliation complete. Linked: {Linked}, Conflicts: {Conflicts}, MoodleOnly: {MoodleOnly}, GibbonOnly: {GibbonOnly}",
                report.LinkedCount, report.ConflictCount, report.MoodleOnly, report.GibbonOnly);

            return report;
        }

        public async Task<DashboardStatsDto> Handle(GetDashboardStatsQuery request, CancellationToken cancellationToken)
        {
            var linkedCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Linked);
            var pendingCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Pending);
            var conflictCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Conflict);
            
            // Unmatched = records with MoodleId but no GibbonId and status Pending
            var allPending = await _userMapRepository.GetPendingMatchesAsync();
            var unmatchedCount = allPending.Count(u => u.GibbonId == null);

            return new DashboardStatsDto(linkedCount, pendingCount, conflictCount, unmatchedCount);
        }

        public async Task<List<PendingMatchDto>> Handle(GetPendingMatchesQuery request, CancellationToken cancellationToken)
        {
            var pendingMaps = await _userMapRepository.GetPendingMatchesAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            var moodleUsers = await _moodleClient.GetAllUsersAsync();

            var result = new List<PendingMatchDto>();

            foreach (var map in pendingMaps)
            {
                var gibbonPerson = map.GibbonId.HasValue 
                    ? gibbonPersons.FirstOrDefault(g => g.GibbonPersonId == map.GibbonId.Value)
                    : null;
                
                var moodleUser = map.MoodleId.HasValue 
                    ? moodleUsers.FirstOrDefault(m => m.Id == map.MoodleId.Value)
                    : null;

                int matchScore = CalculateMatchScore(map, gibbonPerson, moodleUser);

                result.Add(new PendingMatchDto(
                    UserMapId: map.Id,
                    GibbonUserId: map.GibbonId,
                    GibbonName: gibbonPerson?.OfficialName ?? map.Email,
                    GibbonEmail: gibbonPerson?.Email ?? map.Email,
                    MoodleUserId: map.MoodleId,
                    MoodleName: $"{moodleUser?.Firstname} {moodleUser?.Lastname}".Trim(),
                    MoodleEmail: moodleUser?.Email ?? map.Email,
                    IdPUsername: map.Username,
                    EmailScore: matchScore
                ));
            }

            return result;
        }

        public async Task<List<ConflictDto>> Handle(GetConflictsQuery request, CancellationToken cancellationToken)
        {
            var conflictMaps = await _userMapRepository.GetConflictsAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            var moodleUsers = await _moodleClient.GetAllUsersAsync();

            var conflicts = new List<ConflictDto>();

            foreach (var map in conflictMaps)
            {
                var moodleUser = map.MoodleId.HasValue 
                    ? moodleUsers.FirstOrDefault(m => m.Id == map.MoodleId.Value)
                    : null;
                
                var gibbonPerson = map.GibbonId.HasValue
                    ? gibbonPersons.FirstOrDefault(g => g.GibbonPersonId == map.GibbonId.Value)
                    : null;

                if (moodleUser != null || gibbonPerson != null)
                {
                    conflicts.Add(new ConflictDto(
                        MappingId: map.Id,
                        UserName: gibbonPerson?.OfficialName ?? $"{moodleUser?.Firstname} {moodleUser?.Lastname}".Trim(),
                        GibbonEmail: gibbonPerson?.Email ?? map.Email ?? "",
                        MoodleEmail: moodleUser?.Email ?? map.Email ?? "",
                        IssueType: "Email Mismatch",
                        Description: "Multiple users found with same email or conflicting information"
                    ));
                }
            }

            return conflicts;
        }

        public async Task<List<SyncLogDto>> Handle(GetSyncLogsQuery request, CancellationToken cancellationToken)
        {
            var recentLogs = await _userMapRepository.GetRecentSyncLogsAsync(request.Count);
            
            return recentLogs.Select(log => new SyncLogDto(
                Id: log.Id,
                Action: log.Status.ToString(),
                Details: $"MoodleId: {log.MoodleId}, GibbonId: {log.GibbonId}, Email: {log.Email}",
                PerformedBy: "System",
                Timestamp: log.UpdatedAt
            )).ToList();
        }

        public async Task<AutoMatchResult> Handle(AutoMatchCommand request, CancellationToken cancellationToken)
        {
            _logger.LogInformation("Running auto-match algorithm");
            
            var pendingMaps = await _userMapRepository.GetPendingMatchesAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            
            int linkedCount = 0;
            int ignoredCount = 0;

            foreach (var map in pendingMaps.Where(m => m.GibbonId == null && !string.IsNullOrWhiteSpace(m.Email)))
            {
                var exactMatches = gibbonPersons
                    .Where(g => !string.IsNullOrWhiteSpace(g.Email) && 
                               g.Email.Equals(m.Email, StringComparison.OrdinalIgnoreCase))
                    .ToList();

                if (exactMatches.Count == 1)
                {
                    map.GibbonId = exactMatches[0].GibbonPersonId;
                    map.MarkAsLinked(100);
                    await _userMapRepository.UpdateAsync(map);
                    linkedCount++;

                    _logger.LogInformation("Auto-matched UserMap {Id} to GibbonPerson {GibbonId}", map.Id, map.GibbonId);
                }
                else if (exactMatches.Count > 1)
                {
                    map.MarkAsConflict();
                    await _userMapRepository.UpdateAsync(map);
                    ignoredCount++;
                }
            }

            await _userMapRepository.SaveChangesAsync();
            _logger.LogInformation("Auto-match complete. Linked: {Linked}, Ignored: {Ignored}", linkedCount, ignoredCount);

            return new AutoMatchResult(linkedCount, ignoredCount);
        }

        public async Task Handle(ResolveConflictCommand request, CancellationToken cancellationToken)
        {
            _logger.LogWarning("ResolveConflictAsync called with mappingId {MappingId}, link={Link}", request.MappingId, request.Link);
            await Task.CompletedTask;
        }

        public async Task Handle(LinkUsersCommand request, CancellationToken cancellationToken)
        {
            var userMap = await _userMapRepository.GetByGibbonIdAsync(request.GibbonUserId) 
                       ?? await _userMapRepository.GetByMoodleIdAsync(request.MoodleUserId);

            if (userMap == null)
            {
                userMap = new UserMap
                {
                    GibbonId = request.GibbonUserId,
                    MoodleId = request.MoodleUserId
                };
                await _userMapRepository.AddAsync(userMap);
            }
            else
            {
                userMap.GibbonId = request.GibbonUserId;
                userMap.MoodleId = request.MoodleUserId;
                await _userMapRepository.UpdateAsync(userMap);
            }

            userMap.MarkAsLinked(100);
            await _userMapRepository.SaveChangesAsync();
            
            _logger.LogInformation("Manually linked GibbonUser {GibbonId} to MoodleUser {MoodleId}", request.GibbonUserId, request.MoodleUserId);
        }

        public async Task Handle(IgnoreMatchCommand request, CancellationToken cancellationToken)
        {
            var userMap = await _userMapRepository.GetByGibbonIdAsync(request.GibbonUserId);
            
            if (userMap != null && userMap.MoodleId == request.MoodleUserId)
            {
                _logger.LogInformation("Ignored match between GibbonUser {GibbonId} and MoodleUser {MoodleId}", request.GibbonUserId, request.MoodleUserId);
            }
            
            await Task.CompletedTask;
        }


        private static int CalculateMatchScore(UserMap map, GibbonPersonDto? gibbonPerson, MoodleUserDto? moodleUser)
        {
            var score = 0;

            if (!string.IsNullOrWhiteSpace(map.Email) &&
                !string.IsNullOrWhiteSpace(gibbonPerson?.Email) &&
                map.Email.Equals(gibbonPerson.Email, StringComparison.OrdinalIgnoreCase))
            {
                score += 50;
            }

            if (!string.IsNullOrWhiteSpace(map.Email) &&
                !string.IsNullOrWhiteSpace(moodleUser?.Email) &&
                map.Email.Equals(moodleUser.Email, StringComparison.OrdinalIgnoreCase))
            {
                score += 50;
            }

            if (!string.IsNullOrWhiteSpace(map.Username) &&
                !string.IsNullOrWhiteSpace(gibbonPerson?.Username) &&
                map.Username.Equals(gibbonPerson.Username, StringComparison.OrdinalIgnoreCase))
            {
                score += 30;
            }

            if (!string.IsNullOrWhiteSpace(map.Username) &&
                !string.IsNullOrWhiteSpace(moodleUser?.Username) &&
                map.Username.Equals(moodleUser.Username, StringComparison.OrdinalIgnoreCase))
            {
                score += 30;
            }

            return Math.Min(score, 100);
        }
    }
}
