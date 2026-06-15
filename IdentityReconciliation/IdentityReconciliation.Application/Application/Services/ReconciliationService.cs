using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Interfaces;
using Microsoft.Extensions.Logging;

namespace IdentityReconciliation.Application.Services
{
    public class ReconciliationService : IReconciliationService
    {
        private readonly IUserMapRepository _userMapRepository;
        private readonly IMoodleApiClient _moodleClient;
        private readonly IGibbonPersonRepository _gibbonRepo;
        private readonly ILogger<ReconciliationService> _logger;

        public ReconciliationService(
            IUserMapRepository userMapRepository,
            IMoodleApiClient moodleClient,
            IGibbonPersonRepository gibbonRepo,
            ILogger<ReconciliationService> logger)
        {
            _userMapRepository = userMapRepository;
            _moodleClient = moodleClient;
            _gibbonRepo = gibbonRepo;
            _logger = logger;
        }

        public async Task<ReconciliationReport> ReconcileAsync()
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
    }
}
