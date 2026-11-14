import 'package:flutter_test/flutter_test.dart';
import 'package:pollproof_agent/core/geo.dart';

void main() {
  // A 100 m unit area, GPS accepted outright when accurate to 30 m.
  bool inside(int distance, double accuracy) =>
      Geo.insideArea(distance, accuracy, 100, 30);

  test('a good fix inside the area counts', () {
    expect(inside(90, 12), isTrue);
    expect(inside(101, 5), isFalse);
  });

  test('a rough fix counts when its whole margin of error is inside', () {
    // 28 m away, accurate to 48 m: somewhere within 76 m of the unit.
    expect(inside(28, 48), isTrue);
    // 78 m away, accurate to 48 m: could be 126 m away.
    expect(inside(78, 48), isFalse);
  });

  test('a fix worse than twice the accepted error is too vague', () {
    expect(inside(0, 85), isFalse);
  });
}
