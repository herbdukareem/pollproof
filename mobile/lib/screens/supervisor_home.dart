import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/app_state.dart';
import '../core/geo.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/logo.dart';
import 'incident.dart';
import 'messages.dart';
import 'settings.dart';

/// Ward and LGA supervisors: who is at their unit, check-ins to confirm, reserves to send, GPS pins to set.
class SupervisorHome extends StatefulWidget {
  const SupervisorHome({super.key});
  @override
  State<SupervisorHome> createState() => _SupervisorHomeState();
}

class _SupervisorHomeState extends State<SupervisorHome> {
  final app = AppState.instance;
  int tab = 0;
  Map<String, dynamic>? agents;
  List<Map<String, dynamic>> pending = [], units = [];
  String? error;
  bool loading = true;

  @override
  void initState() {
    super.initState();
    load();
  }

  Map<String, String> get scope => app.role == 'ward_supervisor'
      ? {'ward_id': '${app.me?['ward']?['id']}'}
      : {'lga_id': '${app.me?['lga']?['id']}'};

  Future<void> load() async {
    setState(() => loading = true);
    try {
      final r = await Future.wait([
        app.api.get('agents'),
        app.api.get('check-ins'),
        app.api.get('units', scope),
      ]);
      agents = r[0];
      pending = [
        for (final c in r[1]['data'] as List) Map<String, dynamic>.from(c),
      ];
      units = [
        for (final u in r[2]['data'] as List) Map<String, dynamic>.from(u),
      ];
      error = null;
      await app.refresh();
    } on NetworkException {
      error = 'No connection. Pull down to try again.';
    } on ApiException catch (e) {
      error = e.first;
    }
    if (mounted) setState(() => loading = false);
  }

  Future<void> act(Future<dynamic> Function() call, String done) async {
    try {
      await call();
      if (mounted) toast(context, done);
      await load();
    } on ApiException catch (e) {
      if (mounted) toast(context, e.first);
    } on NetworkException {
      if (mounted) toast(context, 'No connection. Try again.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = [
      for (final a in (agents?['data'] as List? ?? []))
        Map<String, dynamic>.from(a),
    ];
    final reserves = rows.where((a) => a['status'] == 'reserve').toList();
    final summary = Map<String, dynamic>.from(agents?['summary'] ?? {});
    final place = app.role == 'ward_supervisor'
        ? '${app.me?['ward']?['name'] ?? ''} · ${app.me?['lga']?['name'] ?? ''}'
        : '${app.me?['lga']?['name'] ?? ''}';
    final noGps = units.where((u) => u['has_gps'] != true).toList();

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: load,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            Container(
              color: C.navy,
              padding: EdgeInsets.fromLTRB(
                20,
                MediaQuery.of(context).padding.top + 14,
                12,
                20,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const PinLogo(size: 26),
                      const SizedBox(width: 8),
                      const Wordmark(size: 20),
                      const Spacer(),
                      IconButton(
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => const MessagesScreen(),
                          ),
                        ),
                        icon: Badge(
                          isLabelVisible: app.unread > 0,
                          label: Text('${app.unread}'),
                          child: const Icon(
                            Icons.notifications_none,
                            color: Colors.white,
                          ),
                        ),
                      ),
                      IconButton(
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => const SettingsScreen(),
                          ),
                        ),
                        icon: const Icon(
                          Icons.account_circle_outlined,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(
                    app.me?['role_label'] ?? 'Supervisor',
                    style: const TextStyle(
                      color: Color(0xFFFFB48A),
                      fontWeight: FontWeight.w800,
                      fontSize: 12.5,
                      letterSpacing: 1,
                    ),
                  ),
                  Text(
                    place,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 22,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      for (final (k, label, color) in [
                        ('present', 'Present', const Color(0xFF5BD39A)),
                        ('absent', 'Absent', C.orange),
                        ('outside_area', 'To confirm', const Color(0xFFFFC94D)),
                        ('reserve', 'Reserves', const Color(0xFF8DB8F2)),
                      ])
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${summary[k] ?? 0}',
                                style: TextStyle(
                                  color: color,
                                  fontSize: 24,
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                              Text(
                                label,
                                style: const TextStyle(
                                  color: Color(0xFFC9D1DE),
                                  fontSize: 12.5,
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
              child: SegmentedButton<int>(
                segments: [
                  const ButtonSegment(value: 0, label: Text('Agents')),
                  ButtonSegment(
                    value: 1,
                    label: Text(
                      'Confirm${pending.isEmpty ? '' : ' · ${pending.length}'}',
                    ),
                  ),
                  ButtonSegment(
                    value: 2,
                    label: Text(
                      'GPS${noGps.isEmpty ? '' : ' · ${noGps.length}'}',
                    ),
                  ),
                ],
                selected: {tab},
                onSelectionChanged: (s) => setState(() => tab = s.first),
              ),
            ),
            if (loading && agents == null)
              const Padding(
                padding: EdgeInsets.all(40),
                child: Center(child: CircularProgressIndicator()),
              ),
            if (error != null)
              Padding(
                padding: const EdgeInsets.all(16),
                child: InfoBox(error!, tone: 'warn'),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: switch (tab) {
                  0 => _agents(rows, reserves),
                  1 => _pending(),
                  _ => _gps(),
                },
              ),
            ),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: C.action,
        foregroundColor: Colors.white,
        onPressed: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => const IncidentScreen()),
        ),
        icon: const Icon(Icons.report_outlined),
        label: const Text('Report incident'),
      ),
    );
  }

  List<Widget> _agents(
    List<Map<String, dynamic>> rows,
    List<Map<String, dynamic>> reserves,
  ) {
    final order = {'absent': 0, 'outside_area': 1, 'present': 2, 'reserve': 3};
    rows.sort(
      (a, b) => (order[a['status']] ?? 9).compareTo(order[b['status']] ?? 9),
    );
    return [
      for (final a in rows)
        Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            title: Text(
              '${a['polling_unit']?['code'] ?? 'Reserve'} · ${a['code']}',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text(
              '${a['name']}${a['result'] != null ? ' · result sent' : ''}',
            ),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                switch (a['status']) {
                  'present' => const Pill('Present'),
                  'absent' => const Pill.bad('Absent'),
                  'outside_area' => const Pill.warn('To confirm'),
                  _ => const Pill.neutral('Reserve'),
                },
                if (a['phone'] != null)
                  IconButton(
                    icon: const Icon(Icons.call, color: C.good),
                    tooltip: 'Call',
                    onPressed: () =>
                        launchUrl(Uri(scheme: 'tel', path: a['phone'])),
                  ),
              ],
            ),
            onTap: a['status'] == 'absent' && reserves.isNotEmpty
                ? () => _sendReserve(a, reserves)
                : null,
          ),
        ),
      if (rows.any((a) => a['status'] == 'absent') && reserves.isNotEmpty)
        const Padding(
          padding: EdgeInsets.only(top: 4),
          child: Text(
            'Tap an absent agent to send a reserve to their unit.',
            textAlign: TextAlign.center,
            style: TextStyle(color: C.ink2, fontSize: 13),
          ),
        ),
    ];
  }

  Future<void> _sendReserve(
    Map<String, dynamic> absent,
    List<Map<String, dynamic>> reserves,
  ) async {
    final chosen = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(
                'Send a reserve to ${absent['polling_unit']?['code']}',
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 17,
                ),
              ),
            ),
            for (final r in reserves)
              ListTile(
                leading: const Icon(Icons.person_outline),
                title: Text('${r['code']} · ${r['name']}'),
                onTap: () => Navigator.pop(ctx, r),
              ),
          ],
        ),
      ),
    );
    if (chosen == null) return;
    await act(
      () => app.api.post('agents/${chosen['id']}/assign', {
        'polling_unit_id': absent['polling_unit']['id'],
      }),
      '${chosen['code']} sent to ${absent['polling_unit']['code']}.',
    );
  }

  List<Widget> _pending() => [
    if (pending.isEmpty)
      const Padding(
        padding: EdgeInsets.all(30),
        child: Text(
          'No check-ins to confirm.',
          textAlign: TextAlign.center,
          style: TextStyle(color: C.ink2),
        ),
      ),
    for (final c in pending)
      Card(
        margin: const EdgeInsets.only(bottom: 10),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                '${c['polling_unit']['code']} · ${c['agent']['code']} ${c['agent']['name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              Text(
                c['distance_m'] == null
                    ? 'Unit has no GPS point · accuracy ${c['accuracy_m']} m'
                    : '${c['distance_m']} m from the unit · accuracy ${c['accuracy_m']} m',
                style: const TextStyle(color: C.ink2, fontSize: 13),
              ),
              if (c['has_selfie'] == true) ...[
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.network(
                    '${app.api.baseUrl}/api/v1/check-ins/${c['id']}/selfie',
                    headers: {'Authorization': 'Bearer ${app.api.token}'},
                    height: 160,
                    fit: BoxFit.cover,
                    errorBuilder: (_, _, _) => const SizedBox(
                      height: 40,
                      child: Center(
                        child: Text('Selfie not available offline'),
                      ),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: C.good,
                        minimumSize: const Size.fromHeight(44),
                      ),
                      onPressed: () => act(
                        () => app.api.post('check-ins/${c['id']}/review', {
                          'decision': 'approve',
                        }),
                        'Check-in approved.',
                      ),
                      child: const Text('Approve'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => act(
                        () => app.api.post('check-ins/${c['id']}/review', {
                          'decision': 'reject',
                        }),
                        'Check-in rejected.',
                      ),
                      child: const Text('Reject'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
  ];

  List<Widget> _gps() => [
    const InfoBox(
      'Stand at the polling unit, then tap "Set pin here". Agents can only check in where a unit has a location.',
      tone: 'navy',
      icon: Icons.push_pin_outlined,
    ),
    const SizedBox(height: 10),
    for (final u in units)
      Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          title: Text(
            '${u['code']} · ${u['name']}',
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          subtitle: Text(u['ward'] ?? ''),
          trailing: u['has_gps'] == true
              ? TextButton(
                  onPressed: () => _pin(u),
                  child: const Text('Move pin'),
                )
              : FilledButton(
                  style: FilledButton.styleFrom(minimumSize: const Size(0, 38)),
                  onPressed: () => _pin(u),
                  child: const Text('Set pin here'),
                ),
        ),
      ),
  ];

  Future<void> _pin(Map<String, dynamic> u) async {
    try {
      final fix = await Geo.current();
      if (!mounted) return;
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text('Set ${u['code']} here?'),
          content: Text(
            'Your location now: accuracy ${fix.accuracy.round()} m. Make sure you are standing at the unit.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Save pin'),
            ),
          ],
        ),
      );
      if (ok == true) {
        await act(
          () => app.api.patch('polling-units/${u['id']}', {
            'latitude': fix.latitude,
            'longitude': fix.longitude,
            'accuracy_m': fix.accuracy.round(),
          }),
          'Location saved for ${u['code']}.',
        );
      }
    } on GeoError catch (e) {
      if (mounted) toast(context, e.message);
    }
  }
}
