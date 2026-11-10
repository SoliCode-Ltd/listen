package studio.listenai.app;

import android.content.Context;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.RectF;
import android.os.SystemClock;
import android.provider.Settings;
import android.view.View;

/**
 * The opening screen: Listen's sound wave on white, moving while the first page loads.
 *
 * It is drawn exactly where and how the system's launch screen draws the still logo
 * (res/drawable/splash_icon.xml: seven bars, 147.5dp wide, centred), so the hand-over from
 * one to the other cannot be seen: the logo simply starts to move. The movement eases in
 * from that still shape, so the first frame is the logo itself.
 */
final class LaunchWaveView extends View {

    static final int BACKGROUND = 0xFFFFFFFF;
    private static final int BAR_COLOR = 0xFF7659FA;

    /** Bar heights of the still logo, in dp (the centre bar is the tallest). */
    private static final float[] HEIGHTS = {12.5f, 55f, 90f, 125f, 90f, 55f, 12.5f};
    private static final float BAR_WIDTH = 12.5f;
    private static final float BAR_STEP = 22.5f;
    /** One swell passes across the logo in this time. */
    private static final float PERIOD_MS = 1150f;
    /** How far a bar shrinks at the low point of the swell, as a share of its height. */
    private static final float DEPTH = 0.46f;
    private static final float EASE_IN_MS = 420f;

    private final Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG);
    private final RectF bar = new RectF();
    private final float density;
    private final boolean moving;
    private long startedAt;

    LaunchWaveView(Context context) {
        super(context);
        density = context.getResources().getDisplayMetrics().density;
        paint.setColor(BAR_COLOR);
        setBackgroundColor(BACKGROUND);
        // The phone is set to show no animations: the still logo, as on the launch screen.
        moving = Settings.Global.getFloat(context.getContentResolver(), Settings.Global.ANIMATOR_DURATION_SCALE, 1f) > 0f;
        // Taps do not reach the page that is still loading underneath.
        setClickable(true);
        setImportantForAccessibility(IMPORTANT_FOR_ACCESSIBILITY_NO);
    }

    /** The height of bar {@code index} at {@code elapsedMs}, as a share of its still height (0..1]. */
    static float scaleAt(int index, float elapsedMs) {
        float ease = Math.min(1f, Math.max(0f, elapsedMs / EASE_IN_MS));
        ease = ease * ease * (3f - 2f * ease);
        // The swell runs outwards from the centre bar, the same on both sides.
        float lag = Math.abs(index - (HEIGHTS.length - 1) / 2f) * 0.62f;
        double phase = 2.0 * Math.PI * (elapsedMs / PERIOD_MS) - lag;
        float dip = (float) (0.5 - 0.5 * Math.cos(phase));
        return 1f - ease * DEPTH * dip;
    }

    @Override
    protected void onAttachedToWindow() {
        super.onAttachedToWindow();
        startedAt = SystemClock.uptimeMillis();
    }

    @Override
    protected void onDraw(Canvas canvas) {
        super.onDraw(canvas);
        float elapsed = moving ? SystemClock.uptimeMillis() - startedAt : 0f;
        float centreX = getWidth() / 2f;
        float centreY = getHeight() / 2f;
        float width = BAR_WIDTH * density;
        float radius = width / 2f;
        for (int i = 0; i < HEIGHTS.length; i++) {
            // Never shorter than it is wide: the end bars stay round dots.
            float height = Math.max(width, HEIGHTS[i] * density * scaleAt(i, elapsed));
            float x = centreX + (i - (HEIGHTS.length - 1) / 2f) * BAR_STEP * density;
            bar.set(x - radius, centreY - height / 2f, x + radius, centreY + height / 2f);
            canvas.drawRoundRect(bar, radius, radius, paint);
        }
        if (moving && isShown()) postInvalidateOnAnimation();
    }
}
