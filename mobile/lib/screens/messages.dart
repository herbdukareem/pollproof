import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/app_state.dart';
import '../theme.dart';
import '../widgets/common.dart';

class MessagesScreen extends StatefulWidget {
  const MessagesScreen({super.key});
  @override
  State<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends State<MessagesScreen> {
  final app = AppState.instance;
  List<Map<String, dynamic>> notices = [];
  bool loading = true;
  String? error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final res = await app.api.get('me/notices');
      notices = [
        for (final n in res['data'] as List) Map<String, dynamic>.from(n),
      ];
      if ((res['unread'] ?? 0) > 0) {
        await app.api.post('me/notices/read');
        app.unread = 0;
      }
      error = null;
    } catch (e) {
      error = 'No connection. Pull down to try again.';
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final sup = app.supervisor;
    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      body: RefreshIndicator(
        onRefresh: load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
          children: [
            if (sup != null)
              Card(
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: C.navy,
                    child: Icon(Icons.support_agent, color: Colors.white),
                  ),
                  title: Text(
                    '${sup['name']}',
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  subtitle: Text('Your ${sup['role']} · ${sup['code'] ?? ''}'),
                  trailing: sup['phone'] == null
                      ? null
                      : IconButton.filled(
                          style: IconButton.styleFrom(backgroundColor: C.good),
                          icon: const Icon(Icons.call),
                          tooltip: 'Call',
                          onPressed: () =>
                              launchUrl(Uri(scheme: 'tel', path: sup['phone'])),
                        ),
                ),
              ),
            const SizedBox(height: 12),
            if (loading)
              const Center(
                child: Padding(
                  padding: EdgeInsets.all(30),
                  child: CircularProgressIndicator(),
                ),
              ),
            if (error != null) InfoBox(error!, tone: 'warn'),
            if (!loading && notices.isEmpty && error == null)
              const Padding(
                padding: EdgeInsets.all(30),
                child: Text(
                  'No messages yet.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: C.ink2),
                ),
              ),
            for (final n in notices)
              Card(
                margin: const EdgeInsets.only(bottom: 10),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          if (n['severity'] == 'high')
                            const Padding(
                              padding: EdgeInsets.only(right: 8),
                              child: Pill.bad('Action needed'),
                            ),
                          if (n['read_at'] == null) const Pill.warn('New'),
                          const Spacer(),
                          Text(
                            hhmm(DateTime.parse(n['created_at'])),
                            style: const TextStyle(
                              color: C.ink2,
                              fontSize: 12.5,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        n['title'],
                        style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        n['body'],
                        style: const TextStyle(
                          color: C.ink2,
                          fontSize: 14,
                          height: 1.4,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
