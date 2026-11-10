package studio.listenai.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

/** The moving logo of the opening screen (LaunchWaveView). */
public class LaunchWaveViewTest {

    @Test
    public void theFirstFrameIsTheStillLogoOfTheLaunchScreen() {
        for (int bar = 0; bar < 7; bar++) {
            assertEquals("bar " + bar, 1f, LaunchWaveView.scaleAt(bar, 0f), 0.0001f);
        }
    }

    @Test
    public void aBarNeverGrowsPastItsStillHeightNorShrinksToNothing() {
        for (int bar = 0; bar < 7; bar++) {
            for (float ms = 0; ms < 6000; ms += 7) {
                float scale = LaunchWaveView.scaleAt(bar, ms);
                assertTrue("bar " + bar + " at " + ms + " is " + scale, scale <= 1.0001f && scale >= 0.5f);
            }
        }
    }

    @Test
    public void theSwellIsTheSameOnBothSidesOfTheCentre() {
        for (float ms = 0; ms < 3000; ms += 37) {
            assertEquals(LaunchWaveView.scaleAt(0, ms), LaunchWaveView.scaleAt(6, ms), 0.0001f);
            assertEquals(LaunchWaveView.scaleAt(2, ms), LaunchWaveView.scaleAt(4, ms), 0.0001f);
        }
    }

    @Test
    public void theLogoMovesOnceItHasEasedIn() {
        float low = 1f;
        float high = 0f;
        for (float ms = 500; ms < 2000; ms += 5) {
            float scale = LaunchWaveView.scaleAt(3, ms);
            low = Math.min(low, scale);
            high = Math.max(high, scale);
        }
        assertTrue("the centre bar swells and sinks", high - low > 0.4f);
    }
}
