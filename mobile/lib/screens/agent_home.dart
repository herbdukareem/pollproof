import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/outbox.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/logo.dart';
import 'checkin.dart';
import 'fallback.dart';
import 'video_screen.dart';
import 'incident.dart';
import 'messages.dart';
import 'onboarding.dart';
import 'outbox_screen.dart';
import 'result_flow.dart';
import 'settings.dart';

class AgentHome extends StatefulWidget {
  const AgentHome({super.key});
  @override
  State<AgentHome> createState() => _AgentHomeState();
}

class _AgentHomeState extends State<AgentHome> {
  final app = AppState.instance;
  Map<String, dynamic>? onboarding;

  @override
  void initState() {
    super.initState();
    _loadOnboarding();
  }

  Future<void> _loadOnboarding() async {
    try {
      final o = await app.api.get('onboarding');
      if (mounted) setState(() => onboarding = o);
    } catch (_) {}
  }

  Future<void> _refresh() async {
    await app.refresh();
    await _loadOnboarding();
  }

  void go(Widget screen) => Navigator.push(
    context,
    MaterialPageRoute(builder: (_) => screen),
  ).then((_) => _refresh());

  OutboxItem? _latest(String kind, {bool todayOnly = false}) {
    final now = DateTime.now();
    bool today(DateTime d) {
      final l = d.toLocal();
      return l.year == now.year && l.month == now.month && l.day == now.day;
    }

    final items = app.outbox.items
        .where((i) => i.kind == kind && (!todayOnly || today(i.createdAt)))
        .toList();
    return items.isEmpty ? null : items.last;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: app,
    builder: (context, _) {
      final unit = app.unit;
      final today = app.today;
      final checkIn = today['check_in'] as Map?;
      // Agents check in every working day, so only today's check-in counts.
      final pendingCheckIn = _latest('check_in', todayOnly: true);
      final sheets = _sheets(today);
      final waiting = app.outbox.waiting.length;
      final step = onboarding?['step'] as int? ?? 5;
      final name = (app.me?['name'] as String? ?? '').split(' ').first;

      final checkedIn =
          checkIn?['counts'] == true ||
          pendingCheckIn?.status == OutboxStatus.sent &&
              pendingCheckIn?.response?['status'] != 'pending_review';
      final checkInNote = checkIn != null
          ? (checkIn['counts'] == true
                ? 'Verified at ${hhmm(DateTime.parse(checkIn['at']))} · ${checkIn['distance_m'] ?? '?'} m'
                : checkIn['status'] == 'pending_review'
                ? 'Waiting for your supervisor to confirm'
                : 'Rejected. Check in again at the unit')
          : pendingCheckIn?.status == OutboxStatus.waiting
          ? 'Saved · sends when there is network'
          : 'Inside the unit area, with GPS';
      final photosDone = sheets.where((sh) => sh.photoDone).length;
      final photosWaiting = sheets.where((sh) => sh.photoWaiting).length;
      final photoDone = sheets.isNotEmpty && photosDone == sheets.length;
      final photoNote = sheets.length > 1
          ? '$photosDone of ${sheets.length} uploaded${photosWaiting > 0 ? ' · the rest upload by themselves when data returns' : ''}'
          : photoDone
          ? 'Uploaded'
          : photosWaiting > 0
          ? 'Uploads by itself when data returns'
          : 'Sent with the result';

      final tasks = [
        (
          Icons.where_to_vote_outlined,
          'Check in at your unit',
          checkInNote,
          checkedIn,
          checkedIn ? null : () => go(const CheckInScreen()),
          null,
        ),
        // One result sheet per race on the unit's ballot.
        for (final sh in sheets)
          (
            Icons.fact_check_outlined,
            sheets.length > 1
                ? '${sh.race!['name']} result'
                : 'Submit the result',
            sh.note,
            sh.done && !sh.sentBack,
            checkedIn || sh.done
                ? () => go(
                    ResultFlow(
                      race: sh.race,
                      correctionOf: sh.done ? sh.result : null,
                    ),
                  )
                : null,
            sh.race?['type'] as String?,
          ),
        (
          Icons.photo_camera_outlined,
          sheets.length > 1 ? 'EC8A photos sent' : 'EC8A photo sent',
          photoNote,
          photoDone,
          null,
          null,
        ),
        (
          Icons.report_outlined,
          'Report incidents',
          '${today['incidents'] ?? 0} reported today',
          false,
          () => go(const IncidentScreen()),
          null,
        ),
      ];
      final sentBack = sheets.where((sh) => sh.sentBack).toList();

      return Scaffold(
        body: RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              Container(
                color: C.navy,
                padding: EdgeInsets.fromLTRB(
                  20,
                  MediaQuery.of(context).padding.top + 14,
                  12,
                  22,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const PinLogo(size: 26),
                        const SizedBox(width: 8),
                        const Wordmark(size: 20),
                        const SizedBox(width: 10),
                        _SyncDot(online: app.outbox.online, waiting: waiting),
                        const Spacer(),
                        IconButton(
                          onPressed: () => go(const SettingsScreen()),
                          icon: const Icon(
                            Icons.account_circle_outlined,
                            color: Colors.white,
                          ),
                          tooltip: 'Profile and settings',
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Text(
                      '${_greeting()}, $name',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 14),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: C.navy2,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: unit == null
                          ? Text(
                              app.me?['is_reserve'] == true
                                  ? "You're a reserve agent. Your supervisor will send you to a unit if needed."
                                  : 'No polling unit assigned yet. Call your supervisor.',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 15,
                              ),
                            )
                          : Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'YOUR POLLING UNIT',
                                  style: TextStyle(
                                    color: Color(0xFFFFB48A),
                                    fontSize: 12,
                                    fontWeight: FontWeight.w800,
                                    letterSpacing: 1.2,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '${unit['code']} · ${unit['name']}',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                Text(
                                  '${unit['ward']} · ${unit['lga']}',
                                  style: const TextStyle(
                                    color: Color(0xFFC9D1DE),
                                    fontSize: 14,
                                  ),
                                ),
                              ],
                            ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (app.rules['declared'] == true) ...[
                      const InfoBox(
                        'The result has been declared. Thank you for protecting the vote.',
                        tone: 'navy',
                        icon: Icons.verified_outlined,
                      ),
                      const SizedBox(height: 14),
                    ] else if (step < 5 && unit != null) ...[
                      _GetReady(
                        step: step,
                        onTap: () =>
                            go(OnboardingScreen(onboarding: onboarding ?? {})),
                      ),
                      const SizedBox(height: 14),
                    ],
                    for (final sh in sentBack) ...[
                      InfoBox(
                        sh.result?['review_note'] ??
                            'The verifier sent your result back.',
                        title: sheets.length > 1
                            ? 'Your ${sh.race!['name']} result was sent back'
                            : 'Your result was sent back',
                        tone: 'bad',
                        icon: Icons.undo,
                      ),
                      const SizedBox(height: 8),
                      FilledButton(
                        onPressed: () => go(
                          ResultFlow(race: sh.race, correctionOf: sh.result),
                        ),
                        child: const Text('Send a correction'),
                      ),
                      const SizedBox(height: 14),
                    ],
                    Row(
                      children: [
                        const Text(
                          'Election-day tasks',
                          style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const Spacer(),
                        Text(
                          '${tasks.where((t) => t.$4).length} of ${tasks.length - 1} done',
                          style: const TextStyle(color: C.ink2, fontSize: 13),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    SectionCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (var i = 0; i < tasks.length; i++) ...[
                            if (i > 0) const Divider(),
                            _TaskTile(
                              icon: tasks[i].$1,
                              title: tasks[i].$2,
                              note: tasks[i].$3,
                              done: tasks[i].$4,
                              onTap: tasks[i].$5,
                              raceType: tasks[i].$6,
                              n: i + 1,
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(height: 14),
                    GridView.count(
                      crossAxisCount: 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      mainAxisSpacing: 10,
                      crossAxisSpacing: 10,
                      childAspectRatio: 2.3,
                      children: [
                        _Quick(
                          Icons.videocam_outlined,
                          'Record video',
                          () => go(const VideoScreen()),
                          tone: C.action,
                        ),
                        _Quick(
                          Icons.chat_bubble_outline,
                          'Messages',
                          () => go(const MessagesScreen()),
                          badge: app.unread,
                        ),
                        _Quick(
                          Icons.outbox_outlined,
                          'Outbox',
                          () => go(const OutboxScreen()),
                          badge: waiting,
                        ),
                        _Quick(
                          Icons.sms_outlined,
                          'If the app fails',
                          () => go(const FallbackScreen()),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Text(
                      waiting == 0
                          ? 'All records sent'
                          : '$waiting waiting to send${app.outbox.online ? '' : ' · no connection'}',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: waiting == 0 ? C.good : C.warn,
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    },
  );

  /// Where each of today's result sheets stands: one per race at the unit (or the one sheet
  /// from a server without races), from the server's record and what is still in the outbox.
  List<_Sheet> _sheets(Map<String, dynamic> today) {
    final races = app.races;
    final results = [
      for (final r in (today['results'] as List? ?? [today['result']]))
        if (r is Map) r,
    ];
    final mine = app.outbox.items.where((i) => i.kind == 'result').toList();
    final photos = app.outbox.items.where((i) => i.kind == 'photo').toList();
    _Sheet sheet(Map<String, dynamic>? race, bool first) {
      final code = race?['code'];
      // Results saved before the app knew about races belong to the first race.
      bool ours(dynamic election) =>
          race == null || election == code || (election == null && first);
      final result = race == null
          ? today['result'] as Map?
          : results.where((r) => ours(r['election'])).firstOrNull;
      final pending = mine.where((i) => ours(i.meta['election'])).lastOrNull;
      final photo = pending == null
          ? null
          : photos.where((p) => p.after == pending.id).lastOrNull;
      return _Sheet(race, result, pending, photo);
    }

    return races.isEmpty
        ? [sheet(null, true)]
        : [for (var i = 0; i < races.length; i++) sheet(races[i], i == 0)];
  }

  String _greeting() {
    final h = DateTime.now().hour;
    return h < 12
        ? 'Good morning'
        : h < 17
        ? 'Good afternoon'
        : 'Good evening';
  }
}

class _SyncDot extends StatelessWidget {
  const _SyncDot({required this.online, required this.waiting});
  final bool online;
  final int waiting;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color: online ? const Color(0x3317694B) : const Color(0x33E8590C),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 7,
          height: 7,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: online ? const Color(0xFF5BD39A) : C.orange,
          ),
        ),
        const SizedBox(width: 5),
        Text(
          online ? 'Online' : 'Offline',
          style: const TextStyle(
            color: Colors.white,
            fontSize: 12,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}

class _GetReady extends StatelessWidget {
  const _GetReady({required this.step, required this.onTap});
  final int step;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    child: Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: C.action, width: 1.5),
          ),
          child: Row(
            children: [
              const Icon(Icons.school_outlined, color: C.action),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Get ready for election day',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                    Text(
                      step < 3
                          ? 'Do a practice run, then the quiz'
                          : step < 4
                          ? 'Pass the 10-question quiz'
                          : 'Confirm you will be at your unit',
                      style: const TextStyle(color: C.ink2, fontSize: 13),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    ),
  );
}

class _TaskTile extends StatelessWidget {
  const _TaskTile({
    required this.icon,
    required this.title,
    required this.note,
    required this.done,
    required this.onTap,
    required this.n,
    this.raceType,
  });
  final IconData icon;
  final String title, note;
  final bool done;
  final VoidCallback? onTap;
  final int n;

  /// A result sheet's race, shown as its tag until the sheet is done.
  final String? raceType;
  @override
  Widget build(BuildContext context) => ListTile(
    onTap: onTap,
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
    leading: raceType != null && !done
        ? RaceTag(raceType, size: 28)
        : CircleAvatar(
            radius: 17,
            backgroundColor: done ? C.good : const Color(0xFFEEF0F4),
            child: done
                ? const Icon(Icons.check, color: Colors.white, size: 18)
                : Icon(icon, color: C.navy, size: 18),
          ),
    title: Text(
      title,
      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
    ),
    subtitle: Text(
      note,
      style: TextStyle(
        fontSize: 12.5,
        color: note.startsWith('Sent back') || note.startsWith('Not accepted')
            ? C.bad
            : C.ink2,
      ),
    ),
    trailing: onTap == null
        ? null
        : (done
              ? const Icon(Icons.chevron_right, color: C.ink3)
              : const Text(
                  'Start',
                  style: TextStyle(
                    color: C.action,
                    fontWeight: FontWeight.w800,
                  ),
                )),
  );
}

class _Quick extends StatelessWidget {
  const _Quick(
    this.icon,
    this.label,
    this.onTap, {
    this.badge = 0,
    this.tone = C.navy,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final int badge;
  final Color tone;
  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    borderRadius: BorderRadius.circular(14),
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: C.line),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            Icon(icon, color: tone, size: 22),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                label,
                style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize: 13.5,
                ),
              ),
            ),
            if (badge > 0)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: C.action,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  '$badge',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
          ],
        ),
      ),
    ),
  );
}

/// One race's result sheet at the agent's unit, for the task list.
class _Sheet {
  _Sheet(this.race, this.result, this.pending, this.photo);
  final Map<String, dynamic>? race;
  final Map? result;
  final OutboxItem? pending, photo;

  bool get sentBack => result?['status'] == 'sent_back';
  bool get done =>
      result != null ||
      pending != null && pending!.status != OutboxStatus.refused;
  bool get photoDone =>
      result?['has_photo'] == true || photo?.status == OutboxStatus.sent;
  bool get photoWaiting => !photoDone && photo != null;

  String get note {
    final r = result, p = pending;
    if (sentBack) return 'Sent back: ${r?['review_note'] ?? 'see messages'}';
    if (r != null) {
      return 'Signed · ref ${r['fingerprint']} · ${r['status'] == 'approved' ? 'verified' : 'waiting for verification'}';
    }
    if (p != null) {
      return p.status == OutboxStatus.refused
          ? 'Not accepted: ${p.error}'
          : p.status == OutboxStatus.sent
          ? 'Signed · ref ${p.meta['fingerprint']} · sent, waiting for verification'
          : 'Signed and saved · ref ${p.meta['fingerprint']} · waiting to send';
    }
    return 'Photo of the EC8A, figures, sign';
  }
}
