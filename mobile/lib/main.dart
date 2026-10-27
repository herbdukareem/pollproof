import 'package:flutter/material.dart';

import 'core/app_state.dart';
import 'screens/agent_home.dart';
import 'screens/collation_home.dart';
import 'screens/login.dart';
import 'screens/supervisor_home.dart';
import 'theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  AppState.instance.start();
  runApp(const PollProofApp());
}

class PollProofApp extends StatefulWidget {
  const PollProofApp({super.key});
  @override
  State<PollProofApp> createState() => _PollProofAppState();
}

class _PollProofAppState extends State<PollProofApp>
    with WidgetsBindingObserver {
  final app = AppState.instance;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Coming back to the app is a good moment to send anything waiting.
    if (state == AppLifecycleState.resumed && app.signedIn) app.refresh();
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'PollProof',
    debugShowCheckedModeBanner: false,
    theme: buildTheme(),
    home: ListenableBuilder(
      listenable: app,
      builder: (context, _) {
        if (!app.ready) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }
        if (!app.signedIn) return const LoginScreen();
        return switch (app.role) {
          'ward_supervisor' || 'lga_supervisor' => const SupervisorHome(),
          'collation_agent' => const CollationHome(),
          _ => const AgentHome(),
        };
      },
    ),
  );
}
